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
 * 本地 Bucket 对外只返回永久虚拟地址 /attachment/{bucket_path}/{md5}.{ext}（file_url 同值），
 * 真实路径由 Nginx X-Accel-Redirect 内部映射；PHP 不读取文件正文。
 */
class index extends CommonController
{
    /**
     * 永远禁止上传的后缀，与 Bucket 的 ext 配置无关。
     * 这些后缀要么会被 Web 服务器当脚本执行（php/phtml/jsp/asp…），
     * 要么会在浏览器里直接运行脚本（html/js/svg 之外的 html 家族），
     * 作为"后台把 ext 配错就变成 RCE / 存储型 XSS"的最后一道闸。
     * 注：svg 不在此列——它是现有 Bucket 的合法白名单项，由分发侧 nosniff 兜。
     */
    private const FORBIDDEN_EXTENSIONS = [
        'php', 'php2', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8',
        'phtml', 'pht', 'phps', 'phar', 'inc',
        'html', 'htm', 'shtml', 'xhtml', 'xht',
        'js', 'mjs', 'jsp', 'jspx', 'jsw', 'jsv', 'asp', 'aspx', 'asa', 'asax',
        'cer', 'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'zsh', 'bat', 'cmd',
        'exe', 'dll', 'com', 'vbs', 'ps1', 'jar', 'war',
        'htaccess', 'htpasswd', 'ini', 'conf', 'userini',
    ];

    /**
     * 服务端嗅探后不允许的 MIME。
     * 客户端 Content-Type 可以随便写，所以判定必须基于文件真实内容。
     */
    private const FORBIDDEN_MIMES = [
        'text/x-php', 'application/x-php', 'application/x-httpd-php',
        'application/x-httpd-php-source', 'text/html', 'application/xhtml+xml',
        'application/x-sh', 'application/x-shellscript', 'text/x-shellscript',
        'application/x-msdownload', 'application/x-dosexec', 'application/x-msdos-program',
    ];

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

        // 校验必须前置在 MD5 去重之前：否则一个本该被拒的文件，只要库里有同内容文件，
        // 就会走"秒传"分支拿到地址，绕过后面所有检查。
        $ext = (string)$file->getOriginalExtension();
        $ext_lower = strtolower($ext);
        if (in_array($ext_lower, self::FORBIDDEN_EXTENSIONS, true)) {
            $this->fail($type, 403, '禁止上传该类型文件：后缀 .' . $ext_lower . ' 属于系统全局高危后缀（脚本/可执行文件），任何存储都不允许上传');
        }
        if (!in_array($ext, explode(',', $proc['ext']))) {
            $this->fail($type, 403, '后缀不符合规范：.' . $ext . ' 不在当前存储允许的后缀列表中（允许：' . $proc['ext'] . '）');
        }
        if ($file->getSize() >= $proc['size'] * 1024) {
            $this->fail($type, 400, '大小不符合规范');
        }

        // 类型判定：客户端提交的 Content-Type（原 getOriginalMime）由请求方随意填写，
        // 改 Content-Type 就能绕过原有 php/html 黑名单，因此改为服务端嗅探文件真实内容。
        $mime = $this->sniffMime($file->getPathname());
        if (in_array($mime, self::FORBIDDEN_MIMES, true)) {
            $this->fail($type, 403, '禁止上传非法文件：检测到文件真实内容类型为 ' . $mime . '（后缀 .' . $ext_lower . '），属于禁止上传的脚本/可执行内容');
        }
        // 图片 polyglot 兜底：`GIF89a<?php ...` 的 MIME 仍是 image/gif，只能靠内容排查
        if (in_array($ext_lower, self::IMAGE_EXTENSIONS, true)
            && $this->hasScriptPolyglot($file->getPathname())) {
            $this->fail($type, 403, '禁止上传非法文件：图片内容中检测到可执行脚本片段（后缀 .' . $ext_lower . '），已拒绝上传');
        }

        // 第二层：按 MD5 全局（跨项目）去重，命中直接秒传返回既有地址，不落盘。
        // 用户确认相同文件只保留一份；sha1 / 后缀 / 当前 Bucket 不再参与命中判断。
        $file_exists = $this->findDuplicate($md5);
        if ($file_exists) {
            $this->getStr($full, $file_exists, $type);
            return;
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
            $this->getStr($full, $duplicate, $type);
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

        // 对外地址统一为虚拟分发地址（local 走 /attachment 与 /file，OSS 保留直链），
        // 真实落盘路径只写入数据库、只在 AOSS 后台可见。
        // up 返回相对虚拟地址、upfull 返回带域名的绝对虚拟地址，保持原有口径。
        $publicInfo = AttachmentDeliveryService::publicRow($file_info, $this->bucket);
        $sav = $full ? (string)$publicInfo['url'] : (string)$publicInfo['path'];

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
                $this->getStr($full, $duplicate, $type);
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
                $this->getStr($full, $duplicate, $type);
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
                    // 所有对外地址字段统一为虚拟地址，真实 path 不随接口返回
                    Ret::Success(0, $publicInfo);
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

    /** 需要在内容层额外排查脚本片段的图片后缀：这类 polyglot 文件 MIME 判定为图片 */
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'ico'];

    /**
     * 服务端嗅探文件真实 MIME（基于 magic bytes，不信任客户端 Content-Type）
     *
     * fileinfo 不可用时降级为 application/octet-stream——此时只剩后缀白名单 +
     * FORBIDDEN_EXTENSIONS 两层防护，属于"少一层"而不是"放行"。
     */
    private function sniffMime(string $path): string
    {
        if (!function_exists('finfo_open')) {
            return 'application/octet-stream';
        }
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return 'application/octet-stream';
        }
        // 不调用 finfo_close：PHP 8.5 起该函数已废弃，finfo 对象自动释放
        $mime = @finfo_file($finfo, $path);

        return is_string($mime) && $mime !== '' ? strtolower($mime) : 'application/octet-stream';
    }

    /**
     * 排查"图片头 + PHP 代码"的 polyglot 文件
     *
     * finfo 只看文件头，`GIF89a<?php ...` 会被判成 image/gif 而通过 MIME 检查。
     * 正常图片不可能包含 PHP 起始标记，所以对图片后缀做一次内容级兜底；
     * 分块读取并回退 4 字节，避免标记正好跨在两块交界处被漏检。
     */
    private function hasScriptPolyglot(string $path): bool
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }

        $found = false;
        while (!feof($fh)) {
            $chunk = fread($fh, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            if (stripos($chunk, '<?php') !== false || str_contains($chunk, '<?=')) {
                $found = true;
                break;
            }
            if (strlen($chunk) > 4) {
                fseek($fh, -4, SEEK_CUR);
            }
        }
        fclose($fh);

        return $found;
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
     * 统一成功响应：新上传与秒传命中都走这里，保证各上传入口返回结构一致
     *
     * 秒传命中时按 path 前缀反查真实 Bucket，所有对外地址一律走虚拟分发，
     * 真实 path 只在 AOSS 后台可见。
     */
    public function getStr(mixed $full, AttachmentModel $file_exists, mixed $type): string
    {
        $row = [
            'id'    => (int)$file_exists['id'],
            'token' => (string)($file_exists['token'] ?? ''),
            'path'  => (string)($file_exists['path'] ?? ''),
            'md5'   => (string)($file_exists['md5'] ?? ''),
            'name'  => (string)($file_exists['name'] ?? ''),
        ];
        $bucket = V3StorageProjectModel::api_match_by_path($row['token'], $row['path']);
        if (empty($bucket)) {
            $bucket = $this->bucket;
        }
        $publicInfo = AttachmentDeliveryService::publicRow($row, $bucket);
        $publicUrl = (string)$publicInfo['url'];
        $sav = $full ? $publicUrl : (string)$publicInfo['path'];
        switch ($type) {
            case "ue":
                Ret::Success(0, ['src' => $sav]);
                break;

            case "complete":
                // 与正常上传口径一致：所有对外地址字段都是虚拟地址
                Ret::Success(0, array_merge($file_exists->toArray(), $publicInfo));
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
