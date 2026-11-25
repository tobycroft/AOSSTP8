<?php

namespace app\v3\file\controller;

use app\v1\file\model\AttachmentModel;
use app\v3\file\service\AttachmentDeliveryService;
use app\v3\storage\model\V3StorageModel;
use app\v3\storage\model\V3StorageProjectModel;
use app\v3\storage\model\V3UploadShardModel;
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
 * 落盘目录（Path）、允许后缀、大小上限以及本地/远程：
 * upload/{Path}/YYYYMMDD/{分片}/md5.ext，其中分片每 1000 个文件递增（1000/2000/...），
 * 避免同一日期目录下文件堆积造成 Linux IO 压力。
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
 *
 * MD5 秒传（数据清洗）：上传前按 md5 全局查 V3 未软删附件，命中直接返回既有
 * path/url 且不落盘；落盘后、OSS 上传前后各复查一次（双重保险），命中则删除
 * 本次临时文件并返回既有地址。SDK 侧另有 md5_check 前置查询。
 *
 * 本地 Bucket 对外只返回虚拟地址 /attachment/{bucket_id}/{md5}（附时限 file_url），
 * 真实路径由 Nginx X-Accel-Redirect 内部映射；PHP 不读取文件正文。
 */
class index extends CommonController
{
    /** V3 附件的归属标记：与 V1/V2 共用 ao_attachment 时用于隔离同名 token */
    protected string $upload_tag = 'v3';
    protected mixed $token;
    protected mixed $proc;
    /** 本次上传命中的 Bucket 配置（虚拟地址/取件码签发用） */
    protected array $bucket = [];
    protected ?string $upload_ip = null;
    /** 当前一次上传命中的 Bucket id，用于当日分片计数（ao_v3_upload_shard） */
    protected int $storageId = 0;

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
        $this->storageId = $storageId;
        $this->bucket = $bucket;
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
     * 落盘前先按 Bucket+日期原子取号决定分片目录，分母 1000，目录名 1000/2000/...。
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
        // 统一小写：MD5 查重、落盘文件名、DB 记录三处口径一致
        $md5 = strtolower((string)$file->md5());
        $sha1 = strtolower((string)$file->sha1());
        $mime = $file->getOriginalMime();

        // 第一层：上传前按 MD5 全局（跨项目）去重，命中直接秒传返回既有地址，不落盘。
        // 用户确认相同文件只保留一份；sha1 / 后缀 / 当前 Bucket 不再参与命中判断。
        $file_exists = $this->findDuplicate($md5);
        if ($file_exists) {
            $this->getStr($full, $this->existingUrl($file_exists, $proc), $file_exists, $type);
            return;
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

        // 当日分片：upload/{bucket}/{YYYYMMDD}/{1000|2000|...}/md5.ext
        // 每个 Bucket+日期原子取号，按每 FILES_PER_SEGMENT 个文件落一个子目录，
        // 避免单日期目录堆积过多文件造成 Linux IO 压力。取号无事务、不锁表。
        $day = date("Ymd");
        $seq = V3UploadShardModel::allocate($this->storageId, $day);
        $segment = V3UploadShardModel::segmentOf($seq);
        $sav_path = $proc['name'] . DIRECTORY_SEPARATOR . $day . DIRECTORY_SEPARATOR . $segment;
        $info = $file->move('./upload' . DIRECTORY_SEPARATOR . $sav_path, $md5 . '.' . $file->getOriginalExtension());
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

        // 第二层：落盘后复查。并发请求可能在本次 move 之前已把同一 md5 写入库，
        // 命中则丢弃本次临时文件，直接返回先入库的那份地址。
        $duplicate = $this->findDuplicate($md5);
        if ($duplicate) {
            @unlink($info->getPathname());
            $this->getStr($full, $this->existingUrl($duplicate, $proc), $duplicate, $type);
            return;
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

        // 对外地址：local 走虚拟分发地址（隐藏真实落盘路径），oss 仍用 Bucket 域名直链
        $publicUrl = AttachmentDeliveryService::publicUrl(
            ['md5' => $md5, 'path' => $fileName],
            $this->bucket,
            true
        );
        $sav = ($full && $publicUrl !== '') ? $publicUrl : $fileName;

        $ossUploaded = false;
        if ($proc['type'] === 'oss') {
            try {
                $oss = new AliyunOSS($proc);
                $ret = $oss->uploadFile($proc['bucket'], $fileName, $info->getPathname());
            } catch (OssException $e) {
                @unlink($info->getPathname());
                $this->fail($type, 200, $e->getMessage());
            }
            if (empty($ret->getData()["info"]["url"])) {
                @unlink($info->getPathname());
                $this->fail($type, 200, "OSS不正常");
            }
            $ossUploaded = true;
            @unlink($info->getPathname());

            // 第三层：OSS 上传较慢，期间可能已有同 md5 文件入库；删掉刚传的远端对象。
            $duplicate = $this->findDuplicate($md5);
            if ($duplicate) {
                try {
                    $oss->deleteFile($proc['bucket'], $fileName);
                } catch (Throwable $ignore) {
                    // 清理失败不影响秒传结果
                }
                $this->getStr($full, $this->existingUrl($duplicate, $proc), $duplicate, $type);
                return;
            }
        }

        try {
            $created = AttachmentModel::create($file_info);
            $file_info['id'] = (int)$created->getKey();
        } catch (Throwable $e) {
            // 并发插入兜底：唯一键冲突时查回先入库那份，按秒传成功返回。
            $duplicate = $this->findDuplicate($md5);
            if ($duplicate) {
                if ($ossUploaded) {
                    try {
                        (new AliyunOSS($proc))->deleteFile($proc['bucket'], $fileName);
                    } catch (Throwable $ignore) {
                        // 清理失败不影响秒传结果
                    }
                }
                @unlink($info->getPathname());
                $this->getStr($full, $this->existingUrl($duplicate, $proc), $duplicate, $type);
                return;
            }
            throw $e;
        }
        if ($info) {
            switch ($type) {
                case "ue":
                    Ret::Success(0, ['src' => $sav]);
                    break;

                case "complete":
                    $file_info["src"] = $sav;
                    $file_info["url"] = $sav;
                    $file_info["surl"] = $file_info['path'];
                    $issued = AttachmentDeliveryService::isLocalBucket($this->bucket)
                        ? AttachmentDeliveryService::issueTimed($file_info, (int)config('attach.ttl'))
                        : [];
                    $file_info['file_url'] = $issued['url'] ?? '';
                    $file_info['file_expired_at'] = $issued['expired_at'] ?? 0;
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

    /**
     * 秒传查重：全局（跨项目）取一条未软删的 V3 附件
     *
     * 与 token::findUploadedGlobal 使用同一口径；这里返回 Model 以复用 getStr。
     */
    private function findDuplicate(string $md5): ?AttachmentModel
    {
        $md5 = strtolower(trim($md5));
        if ($md5 === '') {
            return null;
        }
        return AttachmentModel::where('md5', $md5)
            ->where('tag', $this->upload_tag)
            ->where('is_deleted', 0)
            ->order('id', 'asc')
            ->find();
    }

    /**
     * 秒传返回的 URL 前缀：按既有附件的 path 反查真实 Bucket，避免跨项目命中时用错域名
     */
    private function existingUrl(AttachmentModel $file, array $proc): string
    {
        $bucket = V3StorageProjectModel::api_match_by_path(
            (string)($file['token'] ?? ''),
            (string)($file['path'] ?? '')
        );
        $url = !empty($bucket) ? rtrim((string)($bucket['url'] ?? ''), '/') : '';
        return $url !== '' ? $url : rtrim((string)($proc['url'] ?? ''), '/');
    }

    /**
     * 统一成功响应：新上传与秒传命中都走这里，保证各上传入口返回结构一致
     */
    public function getStr(mixed $full, $url, AttachmentModel $file_exists, mixed $type): string
    {
        $row = [
            'id'    => (int)$file_exists['id'],
            'token' => (string)($file_exists['token'] ?? ''),
            'path'  => (string)($file_exists['path'] ?? ''),
            'md5'   => (string)($file_exists['md5'] ?? ''),
            'name'  => (string)($file_exists['name'] ?? ''),
        ];
        $bucket = V3StorageProjectModel::api_match_by_path($row['token'], $row['path']);
        $publicUrl = AttachmentDeliveryService::publicUrl($row, $bucket, true);
        if (empty($bucket) && $publicUrl === '') {
            $urlPrefix = rtrim((string)$url, '/');
            $publicUrl = $urlPrefix !== '' ? $urlPrefix . '/' . $row['path'] : $row['path'];
        }
        $sav = $full ? $publicUrl : $row['path'];
        switch ($type) {
            case "ue":
                Ret::Success(0, ['src' => $sav]);
                break;

            case "complete":
                $file_exists["src"] = $row['path'];
                $file_exists["url"] = $publicUrl;
                $file_exists["surl"] = $row['path'];
                $issued = AttachmentDeliveryService::isLocalBucket($bucket)
                    ? AttachmentDeliveryService::issueTimed($row, (int)config('attach.ttl'))
                    : [];
                $file_exists['file_url'] = $issued['url'] ?? '';
                $file_exists['file_expired_at'] = $issued['expired_at'] ?? 0;
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
