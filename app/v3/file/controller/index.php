<?php

namespace app\v3\file\controller;

use app\v1\file\action\OssSelectionAction;
use app\v1\file\model\AttachmentModel;
use app\v3\common\UploadFolder;
use app\v3\storage\model\V3StorageModel;
use BaseController\CommonController;
use Exception;
use getID3;
use Input;
use Net;
use OSS\AliyunOSS;
use OSS\Core\OssException;
use Ret;
use think\facade\Db;
use think\Request;
use Throwable;

/**
 * v3 文件上传：直传落盘入口（V3 独立实现，不继承 V1/V2）
 *
 * 上传落盘逻辑与 V1 upload_file 完全一致，但刻意在本控制器内独立实现一份，
 * 不继承 v2/file/index，避免 V3 与 V1/V2 的继承链耦合：V3 的存储来自独立表
 * ao_v3_storage，而 V2 写死从 ao_oss 装载 proc，两者链路不同。
 *
 * 鉴权方式：
 *   浏览器/客户端拿「一次性 token」直传，直传请求无法携带自定义鉴权头，
 *   也不能把 secret 交给前端。因此 V3 的凭证信封鉴权前移到签发接口
 *   （v3/file/token），直传阶段沿用 ao_attachment_token 的一次性 token。
 *
 * initialize 从一次性 token 里的 oss_token 定位 ao_v3_storage 行，直接作为
 * V1 落盘所需的 $proc（本表字段与 ao_oss 对齐），并用存储项的 upload_folder
 * 覆盖落盘目录：upload/{文件夹}/YYYYMMDD/md5.ext，为空回退存储名称。
 * local 存本站、OSS 传远程，逻辑与 V1 逐字一致。
 *
 * 可用入口：
 *   POST /v3/file/index/upfull      完整上传，返回文件地址
 *   POST /v3/file/index/up          完整上传（相对路径）
 *   POST /v3/file/index/uphash      hash 模式，仅返回 md5
 *   POST /v3/file/index/up_complete 返回完整文件信息
 *
 * 一次性 token 由 v3/file/token 的 create/upload_url/upload_url_hash 签发。
 */
class index extends CommonController
{
    protected string $upload_tag = '';
    protected mixed $token;
    protected mixed $proc;
    protected ?string $upload_ip = null;

    public function initialize()
    {
        set_time_limit(0);
        parent::initialize();

        $tempToken = Input::Get('token');

        // 一次性 token 校验。用查询构造器取数组：避开 Model 读取字符串属性在
        // PHP 8.6 下触发 is_subclass_of(..., false) 弃用告警（生产 PHP 8.5 无影响）。
        $fileToken = Db::name('ao_attachment_token')
            ->where('token', $tempToken)
            ->where('expired_at', '>', date('Y-m-d H:i:s'))
            ->where('is_used', 0)
            ->find();
        if (empty($fileToken)) {
            Ret::Fail(401, null, '临时token无效或已过期');
        }

        $folder = UploadFolder::normalize($fileToken['upload_folder'] ?? '');

        // 从独立存储表装载 proc（本表字段与 ao_oss 对齐，可直接作为 V1 落盘的 $proc）
        $storageToken = (string)$fileToken['oss_token'];
        $storage = V3StorageModel::api_find_token($storageToken);
        if (empty($storage)) {
            Ret::Fail(401, null, '存储配置不可用');
        }
        $this->token = $storageToken;
        $this->proc = OssSelectionAction::App_find_byProc($storage);
        if (($this->proc['type'] ?? 'none') === 'none') {
            Ret::Fail(400, null, '本项目没有存储权限');
        }

        // upload_folder 覆盖落盘目录名；为空则保留存储名称（V1 默认行为）
        if ($folder !== '' && UploadFolder::isValid($folder)) {
            $this->proc['name'] = $folder;
        }

        // IP 处理 + 标记 used（与 V1/V2 行为一致）
        $recordIp = request()->ip();
        if (!empty($fileToken['ip'])) {
            $this->upload_ip = $fileToken['ip'];
            $recordIp = $fileToken['ip'];
        }
        Db::name('ao_attachment_token')
            ->where('id', $fileToken['id'])
            ->update([
                'is_used' => 1,
                'ip' => $recordIp,
                'update_time' => date('Y-m-d H:i:s'),
            ]);
    }

    public function up(Request $request)
    {
        try {
            $file = $request->file('file');
            if ($file) {
                try {
                    $this->upload_file($request);
                } catch (Exception $e) {
                    Ret::Fail(400, $e->getTraceAsString(), $e->getMessage());
                }
            } else {
                Ret::Fail(400, null, '请上传binary文件');
            }
        } catch (Throwable $e) {
            Ret::Fail(400, $e->getTraceAsString(), $e->getMessage());
        }
    }

    public function upfull(Request $request)
    {
        try {
            $file = $request->file('file');
            if ($file) {
                try {
                    $this->upload_file($request, 1);
                } catch (Exception $e) {
                    Ret::Fail(400, $e->getTraceAsString(), $e->getMessage());
                }
            } else {
                Ret::Fail(400, null, '请上传binary文件');
            }
        } catch (Throwable $e) {
            Ret::Fail(400, $e->getTraceAsString(), $e->getMessage());
        }
    }

    public function uphash(Request $request)
    {
        $this->upload_file($request, 1, 'hash');
    }

    public function up_complete(Request $request)
    {
        try {
            $file = $request->file('file');
            if ($file) {
                $this->upload_file($request, 1, 'complete');
            } else {
                Ret::Fail(400, null, '请上传binary文件');
            }
        } catch (Throwable $e) {
            Ret::Fail(400, $e->getTraceAsString(), $e->getMessage());
        }
    }

    /**
     * 上传落盘主逻辑：与 V1 index::upload_file 逐字一致（唯一差异是 $proc 来自
     * 独立的 ao_v3_storage，且 name 已被 initialize 按 upload_folder 覆盖）。
     */
    public function upload_file(Request $request, $full = 0, $type = null)
    {
        $token = $this->token;
        $proc = $this->proc;

        $file = $request->file('file');
        if (!$file) {
            Ret::Fail(400, null, 'file字段没有用文件提交');
        }
        $file_name = $file->getOriginalName();
        $md5 = $file->md5();
        $sha1 = $file->sha1();
        $mime = $file->getOriginalMime();

        $file_exists = AttachmentModel::where(['token' => $token, 'md5' => $md5, 'sha1' => $sha1, 'tag' => $this->upload_tag])->find();

        if ($file_exists) {
            if ($proc['type'] != 'all' || file_exists('./upload/' . $file_exists['path'])) {
                $sav = $this->getStr($full, $proc['url'], $file_exists, $type);
            }
        }
        if ($file->getOriginalMime() == 'text/x-php' || $file->getOriginalMime() == 'text/html') {
            Ret::Fail(403, null, '禁止上传非法文件');
        }
        if ($file->getSize() >= $proc['size'] * 1024) {
            Ret::Fail(400, null, '大小不符合规范');
        }
        if (!in_array($file->getOriginalExtension(), explode(',', $proc['ext']))) {
            Ret::Fail(403, null, '后缀不符合规范');
        }

        $sav_path = $proc['name'] . DIRECTORY_SEPARATOR . date("Ymd");
        $info = $file->move('./upload' . DIRECTORY_SEPARATOR . $sav_path, $file->md5() . '.' . $file->getOriginalExtension());
        if (!$info) {
            Ret::Fail(300, null, '文件错误');
        }

        $fileName = $sav_path . '/' . $info->getFilename();
        $fileName = str_replace("\\", "/", $fileName);

        $duration = 0;
        $duration_str = "00:00";
        $bitrate = 0;
        $width = 0;
        $height = 0;

        $ext = $info->getExtension();

        switch ($ext) {
            case "mp3":
            case "wav":
            case "ogg":
            case "asf":
            case "wmv":
            case "avi":
            case "mp4":
            case "aac":
                $getId3 = new getID3();
                $ana = $getId3->analyze($info->getPathname());
                $duration = $ana["playtime_seconds"];
                $bitrate = $ana["bitrate"];
                $duration_str = $ana["playtime_string"];
                break;

            case "png":
            case "jpg":
            case "jpeg":
            case "bmp":
            case "gif":
            case "tiff":
                $getId3 = new getID3();
                $ana = $getId3->analyze($info->getPathname());
                $video = $ana["video"] ?? [];
                $width = empty($video['resolution_x']) ? 0 : $video['resolution_x'];
                $height = empty($video['resolution_y']) ? 0 : $video['resolution_y'];
                if (!empty($video)) {
                    $bitrate = empty($video['bits_per_sample']) ? 0 : $video['bits_per_sample'];
                    $duration_str = empty($video['compression_ratio']) ? "00:00" : $video['compression_ratio'];
                }
                break;

            default:
                break;
        }

        $ip = $this->upload_ip ?? request()->ip();

        $file_info = [
            'token' => $token,
            'tag' => $this->upload_tag,
            'name' => $file_name,
            'mime' => $mime,
            'path' => $fileName,
            'ext' => $ext,
            'size' => $info->getSize(),
            'md5' => $md5,
            'sha1' => $sha1,
            'width' => $width,
            'height' => $height,
            'duration' => $duration,
            'duration_str' => $duration_str,
            'bitrate' => $bitrate,
            'ip' => $ip,
        ];

        if ($proc["type"] == "local" || $proc["type"] == "all") {
            if ($proc['main_type'] == 'local') {
                $sav = ($full ? $proc['url'] . '/' : '') . $fileName;
            }
        }
        if ($proc["type"] == "dp") {
            $ret = Net::PostFile('http://' . $proc["endpoint"] . '/up?token=' . $proc["bucket"], realpath('./upload/' . $fileName), $file->getFileInfo('type'), $file->getFileInfo('name'));
            $json = json_decode($ret, 1);
            $sav = ($full ? $proc['url'] . '/' : '') . $json["data"];
        }
        if ($proc["type"] == "oss" || $proc["type"] == "all") {
            try {
                $oss = new AliyunOSS($proc);
                $ret = $oss->uploadFile($proc['bucket'], $fileName, $info->getPathname());
            } catch (OssException $e) {
                Ret::Fail(200, null, $e->getMessage());
            }
            if (empty($ret->getData()["info"]["url"])) {
                Ret::Fail(200, null, "OSS不正常");
            }
            if ($proc['main_type'] == 'oss') {
                $sav = ($full ? $proc['url'] . '/' : '') . $fileName;
            }
            if ($proc["type"] != "all") {
                unlink($info->getPathname());
            }
        }

        AttachmentModel::create($file_info);
        if ($info) {
            switch ($type) {
                case "ue":
                    Ret::Success(0, ['src' => $sav]);
                    break;

                case "complete":
                    $file_info["src"] = $sav;
                    $file_info["url"] = $proc['url'] . '/' . $file_info['path'];
                    $file_info["surl"] = $file_info['path'];
                    Ret::Success(0, $file_info);
                    break;

                case "hash":
                    Ret::Success(0, $file_info['md5']);
                    break;

                default:
                    Ret::Success(0, $sav);
                    break;
            }
        } else {
            Ret::Fail(300, null, null);
        }
    }

    public function getStr(mixed $full, $url, AttachmentModel $file_exists, mixed $type): string
    {
        $sav = ($full ? $url . '/' : '') . $file_exists['path'];
        switch ($type) {
            case "ue":
                Ret::Success(0, ['src' => $sav]);
                break;

            case "complete":
                $file_exists["src"] = $file_exists['path'];
                $file_exists["url"] = $url . '/' . $file_exists['path'];
                $file_exists["surl"] = $file_exists['path'];
                Ret::Success(0, $file_exists);
                break;

            case "hash":
                Ret::Success(0, $file_exists['md5']);
                break;

            default:
                Ret::Success(0, $sav);
                break;
        }
        return $sav;
    }
}
