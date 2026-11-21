<?php

namespace app\v3\file\controller;

use app\v1\file\model\AttachmentModel;
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
 * 上传落盘逻辑与 V1 upload_file 一致，但刻意在本控制器内独立实现一份，
 * 不继承 v2/file/index，避免 V3 与 V1/V2 的继承链耦合：V3 的存储来自
 * ao_v3_storage（Bucket），而 V2 写死从 ao_oss 装载 proc，两者链路不同。
 *
 * 鉴权方式：
 *   浏览器/客户端拿「一次性 token」直传，直传请求无法携带自定义鉴权头，
 *   也不能把 secret 交给前端。因此 V3 的凭证信封鉴权前移到签发接口
 *   （v3/file/token），直传阶段沿用 ao_attachment_token 的一次性 token。
 *
 * initialize 按一次性 token 里的 storage_id 定位 Bucket，由 Bucket 决定
 * 落盘目录（Path）、允许后缀、大小上限以及本地/远程：upload/{Path}/YYYYMMDD/md5.ext。
 *
 * 可用入口（与 V1/V2 上传能力对齐）：
 *   POST /v3/file/index/upfull      完整上传，返回完整文件地址
 *   POST /v3/file/index/up          完整上传（相对路径）
 *   POST /v3/file/index/up_ue       编辑器上传，返回 src
 *   POST /v3/file/index/up_wangeditor wangEditor 自定义上传，返回 {errno,data:{url,alt,href}}
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

        // 按一次性 token 记录的 storage_id 定位 Bucket，并组装落盘用 proc
        $storageId = (int)($fileToken['storage_id'] ?? 0);
        $bucket = V3StorageModel::api_find_id($storageId);
        if (empty($bucket)) {
            Ret::Fail(401, null, '存储配置不可用');
        }
        $this->token = V3StorageModel::api_group_key($storageId);
        $this->proc = V3StorageModel::api_proc($bucket);

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

    public function up_ue(Request $request)
    {
        try {
            $file = $request->file('file');
            if ($file) {
                $this->upload_file($request, 1, 'ue');
            } else {
                Ret::Fail(400, null, '请上传binary文件');
            }
        } catch (Throwable $e) {
            Ret::Fail(400, $e->getTraceAsString(), $e->getMessage());
        }
    }

    /**
     * wangEditor 自定义上传（v5 customUpload）
     *
     * 前端把选中的文件以 file 字段 multipart 提交到本接口，
     * 返回 {errno:0,data:{url,alt,href}}；失败返回 {errno:1,message}。
     * 鉴权沿用一次性 token：?token=xxx（由 v3/file/token/create 签发）。
     */
    public function up_wangeditor(Request $request)
    {
        try {
            if (!$request->file('file')) {
                $this->fail('wangeditor', 400, '请上传文件');
            }
            $this->upload_file($request, 1, 'wangeditor');
        } catch (Throwable $e) {
            $this->fail('wangeditor', 400, $e->getMessage());
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
     * 上传落盘主逻辑：对齐 V1 index::upload_file 的行为
     *
     * proc 由 Bucket 组装而来（name 已按 Path 覆盖，oss 时已合并 ao_oss_aliyun 凭据），
     * 因此这里只需区分 local / oss 两种落点，没有 all / dp 之类的历史分支。
     */
    public function upload_file(Request $request, $full = 0, $type = null)
    {
        $token = $this->token;
        $proc = $this->proc;

        $file = $request->file('file');
        if (!$file) {
            $this->fail($type, 400, 'file字段没有用文件提交');
        }
        $file_name = $file->getOriginalName();
        $md5 = $file->md5();
        $sha1 = $file->sha1();
        $mime = $file->getOriginalMime();

        $file_exists = AttachmentModel::where(['token' => $token, 'md5' => $md5, 'sha1' => $sha1, 'tag' => $this->upload_tag])->find();

        if ($file_exists) {
            $sav = $this->getStr($full, $proc['url'], $file_exists, $type);
        }
        if ($file->getOriginalMime() == 'text/x-php' || $file->getOriginalMime() == 'text/html') {
            $this->fail($type, 403, '禁止上传非法文件');
        }
        if ($file->getSize() >= $proc['size'] * 1024) {
            $this->fail($type, 400, '大小不符合规范');
        }
        if (!in_array($file->getOriginalExtension(), explode(',', $proc['ext']))) {
            $this->fail($type, 403, '后缀不符合规范');
        }

        $sav_path = $proc['name'] . DIRECTORY_SEPARATOR . date("Ymd");
        $info = $file->move('./upload' . DIRECTORY_SEPARATOR . $sav_path, $file->md5() . '.' . $file->getOriginalExtension());
        if (!$info) {
            $this->fail($type, 300, '文件错误');
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

        // 返回地址：local 直接给出 upload/ 下的相对（或带域名）路径；oss 传完后再拼域名
        $urlPrefix = rtrim((string)($proc['url'] ?? ''), '/');
        $sav = ($full && $urlPrefix !== '') ? $urlPrefix . '/' . $fileName : $fileName;

        if ($proc['type'] === 'oss') {
            try {
                $oss = new AliyunOSS($proc);
                $ret = $oss->uploadFile($proc['bucket'], $fileName, $info->getPathname());
            } catch (OssException $e) {
                $this->fail($type, 200, $e->getMessage());
            }
            if (empty($ret->getData()["info"]["url"])) {
                $this->fail($type, 200, "OSS不正常");
            }
            unlink($info->getPathname());
        }

        AttachmentModel::create($file_info);
        if ($info) {
            switch ($type) {
                case "ue":
                    Ret::Success(0, ['src' => $sav]);
                    break;

                case "complete":
                    $file_info["src"] = $sav;
                    $file_info["url"] = $urlPrefix !== '' ? $urlPrefix . '/' . $file_info['path'] : $file_info['path'];
                    $file_info["surl"] = $file_info['path'];
                    Ret::Success(0, $file_info);
                    break;

                case "hash":
                    Ret::Success(0, $file_info['md5']);
                    break;

                case "wangeditor":
                    $this->wangEditorSuccess($sav, $file_name);
                    break;

                default:
                    Ret::Success(0, $sav);
                    break;
            }
        } else {
            $this->fail($type, 300, null);
        }
    }

    public function getStr(mixed $full, $url, AttachmentModel $file_exists, mixed $type): string
    {
        $urlPrefix = rtrim((string)$url, '/');
        $sav = ($full && $urlPrefix !== '') ? $urlPrefix . '/' . $file_exists['path'] : $file_exists['path'];
        switch ($type) {
            case "ue":
                Ret::Success(0, ['src' => $sav]);
                break;

            case "complete":
                $file_exists["src"] = $file_exists['path'];
                $file_exists["url"] = $urlPrefix !== '' ? $urlPrefix . '/' . $file_exists['path'] : $file_exists['path'];
                $file_exists["surl"] = $file_exists['path'];
                Ret::Success(0, $file_exists);
                break;

            case "hash":
                Ret::Success(0, $file_exists['md5']);
                break;

            case "wangeditor":
                $this->wangEditorSuccess($sav, (string)($file_exists['name'] ?? ''));
                break;

            default:
                Ret::Success(0, $sav);
                break;
        }
        return $sav;
    }

    /**
     * wangEditor 上传成功响应：{errno:0,data:{url,alt,href}}
     */
    private function wangEditorSuccess(string $url, string $alt = ''): void
    {
        header('Content-type: application/json');
        echo json_encode([
            'errno' => 0,
            'data' => [
                'url' => $url,
                'alt' => $alt,
                'href' => $url,
            ],
        ], 320 | JSON_INVALID_UTF8_SUBSTITUTE);
        exit(0);
    }

    /**
     * 失败响应：wangeditor 模式输出 {errno:1,message}，其余沿用 Ret 信封
     */
    private function fail(mixed $type, int $code, ?string $echo): void
    {
        if ($type === 'wangeditor') {
            header('Content-type: application/json');
            echo json_encode([
                'errno' => 1,
                'message' => ($echo === null || $echo === '') ? '上传失败' : $echo,
            ], 320 | JSON_INVALID_UTF8_SUBSTITUTE);
            exit(0);
        }
        Ret::Fail($code, null, (string)$echo);
    }
}
