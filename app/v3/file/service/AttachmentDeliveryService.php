<?php

namespace app\v3\file\service;

use app\v1\system\model\SystemParamModel;
use app\v3\project\model\V3ProjectModel;
use app\v3\storage\model\V3StorageModel;
use app\v3\storage\model\V3StorageProjectModel;
use think\facade\Cache;
use think\facade\Db;
use Throwable;

/**
 * V3 附件分发服务
 *
 * PHP 只做鉴权、元数据查询和响应头设置，文件正文由 Nginx 通过
 * X-Accel-Redirect 直接读取磁盘输出。本类禁止出现 readfile /
 * file_get_contents / fopen 循环输出等任何读取文件正文的操作。
 *
 * 两种入口（URL 末尾均附带安全后缀，供 nginx 按扩展名识别 MIME，避免无后缀
 * 落到 application/octet-stream 触发下载；旧的无后缀地址继续兼容）：
 *   虚拟地址：{domain}/attachment/{bucket_path}/{md5}.{ext} —— 永久地址，按 Bucket path + MD5 定位
 *   时限地址：{domain}/file/{code}.{ext}                   —— 取件码，含到期时间与签名
 *
 * {domain} 取该附件所属 Bucket 配置的 url（后台「存储管理 V3」的访问域名），
 * 未配置时回退全局 attach_domain，见 bucketDomain()。
 *
 * 取件码格式：base64("{end_time}|{attachment_id}|{md5(end_time|id|secret)}")
 * 使用标准 Base64（保留 + / = 填充），与既有取件码格式一致。
 * secret 取附件所属项目的 ao_v3_project.secret，不随取件码下发。
 */
final class AttachmentDeliveryService
{
    /** 分发解析缓存 key 前缀：v: 为 永久地址（bucket_path+md5），c: 为取件码（attachment id） */
    private const CACHE_PREFIX = 'v3:deliver:';

    /** 允许 inline 输出的类型；SVG/HTML 等一律按附件下载，避免同源脚本执行 */
    private const INLINE_TYPES = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'bmp'  => 'image/bmp',
        'webp' => 'image/webp',
    ];

    /**
     * 禁止出现在 URL 后缀中的扩展名：脚本、可执行、样式与 SVG/HTML。
     * 这些类型即使 MIME 正确也必须以附件下载，避免同源脚本执行与内容嗅探。
     */
    private const UNSAFE_URL_EXTS = [
        'svg', 'svgz', 'html', 'htm', 'xhtml', 'shtml',
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phps',
        'js', 'mjs', 'cjs', 'jsx', 'ts', 'tsx', 'vbs', 'vba',
        'jsp', 'jspx', 'asp', 'aspx', 'asa', 'cer', 'cgi', 'fcgi',
        'pl', 'py', 'rb', 'sh', 'bash', 'zsh', 'bat', 'cmd', 'ps1',
        'exe', 'msi', 'com', 'scr', 'dll', 'so', 'swf',
        'css', 'scss', 'less', 'htaccess', 'htpasswd',
    ];

    /**
     * 全局对外域名前缀：system_param.attach_domain 优先，.env 仅作本地兜底。
     *
     * 这是「Bucket 未单独配置 url」时的兜底域名。Bucket 自己的域名请走
     * bucketDomain()：不同项目可能挂在不同域名下。
     *
     * 列表页每个附件都会取域名，故做进程内静态缓存；PHP-FPM 每请求独立，
     * 配置变更在下一请求即可生效。配置表不可用时降级到 env，避免分发中断。
     */
    public static function domain(): string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $fromDb = '';
        try {
            $fromDb = trim((string)SystemParamModel::api_find_key('attach_domain'));
        } catch (Throwable $e) {
            // 降级到 env
        }
        $configured = $fromDb !== '' ? $fromDb : (string)config('attach.domain');
        return $cached = rtrim($configured, '/');
    }

    /**
     * Bucket 自己的对外域名：ao_v3_storage.url 优先，留空回退全局 attach_domain。
     *
     * 一个 Bucket 对应一个项目/站点，各项目返回地址的域名可能不同：
     *   - local Bucket：url 是该 Bucket 虚拟目录（/attachment、/file）的域名；
     *   - oss   Bucket：url 是 OSS 直链前缀，语义与原来一致。
     * 因此这里不区分 type，统一按「对外地址前缀」处理。
     */
    public static function bucketDomain(array $bucket): string
    {
        $own = rtrim(trim((string)($bucket['url'] ?? '')), '/');
        return $own !== '' ? $own : self::domain();
    }

    /** Bucket 的对外路径段：path 优先，留空回退 name */
    public static function bucketSegment(array $bucket): string
    {
        $segment = trim((string)($bucket['path'] ?? ''));
        if ($segment === '') {
            $segment = trim((string)($bucket['name'] ?? ''));
        }
        return $segment;
    }

    /**
     * URL 末尾的安全后缀（含前导点），无安全后缀返回空串。
     *
     * nginx 的 alias 直出模式下 Content-Type 只由请求 URI 的扩展名决定，
     * 与 X-Accel-Redirect 的路径无关。签发时带上真实后缀，浏览器才能 inline。
     * 优先取 ext 字段，缺失时从 path 推断；仅允许字母数字短后缀且不在禁用表。
     */
    public static function urlSuffix(array $row): string
    {
        $ext = strtolower(ltrim(trim((string)($row['ext'] ?? '')), '.'));
        if ($ext === '') {
            $ext = strtolower((string)pathinfo(self::normalizePath((string)($row['path'] ?? '')), PATHINFO_EXTENSION));
        }
        if (preg_match('/^[a-z0-9]{1,10}$/', $ext) !== 1 || in_array($ext, self::UNSAFE_URL_EXTS, true)) {
            return '';
        }
        return '.' . $ext;
    }

    /** 剥离 URL 末尾的扩展名，兼容新（带后缀）旧（无后缀）地址 */
    public static function stripUrlSuffix(string $value): string
    {
        return preg_replace('/\.[A-Za-z0-9]{1,10}$/', '', $value) ?? $value;
    }

    /** 永久虚拟地址的相对路径（本地 Bucket 专用）：/attachment/{bucket_path}/{md5}.{ext} */
    public static function virtualPath(string $bucketPath, string $md5, string $suffix = ''): string
    {
        $bucketPath = trim($bucketPath, '/');
        if ($bucketPath === '' || preg_match('/^[A-Za-z0-9._\-]+$/', $bucketPath) !== 1
            || preg_match('/^[0-9a-f]{32}$/', $md5) !== 1) {
            return '';
        }
        // 后缀只接受 “.字母数字” 形式，杜绝外部拼接路径或查询串
        $suffix = preg_match('/^\.[A-Za-z0-9]{1,10}$/', $suffix) === 1 ? $suffix : '';
        return '/attachment/' . $bucketPath . '/' . strtolower($md5) . $suffix;
    }

    /**
     * 永久虚拟地址（本地 Bucket 专用）：domain + virtualPath
     *
     * $domain 传 Bucket 自己的域名（见 bucketDomain）；留空回退全局 attach_domain。
     */
    public static function virtualUrl(string $bucketPath, string $md5, string $suffix = '', string $domain = ''): string
    {
        $path = self::virtualPath($bucketPath, $md5, $suffix);
        if ($path === '') {
            return '';
        }
        $domain = rtrim(trim($domain), '/');
        return ($domain !== '' ? $domain : self::domain()) . $path;
    }

    /**
     * 对外的相对地址：local 为 /attachment/{bucket}/{md5}.{ext}，OSS 保留原对象键。
     *
     * 用于 src / surl / path 等「相对地址」字段，避免把真实落盘路径返回给客户端。
     * OSS 的 path 是对象键（原直链中也包含），保持原值以兼容既有 SDK 约定。
     */
    public static function publicPath(array $row, array $bucket): string
    {
        if (!self::isLocalBucket($bucket)) {
            return self::normalizePath((string)($row['path'] ?? ''));
        }
        return self::virtualPath(
            self::bucketSegment($bucket),
            (string)($row['md5'] ?? ''),
            self::urlSuffix($row)
        );
    }

    /**
     * 对外附件行：所有地址字段统一改为虚拟地址，真实落盘路径只在 AOSS 后台可见。
     *
     * 字段口径：
     *   - path / src / surl：虚拟相对地址（local 为 /attachment/...；OSS 保留原对象键）
     *   - url              ：虚拟绝对地址（domain + /attachment/...；OSS 为原直链）
     *   - file_url         ：ttl>0 时签发 /file/{code}.{ext} 时限取件码，否则同 url
     *   - file_expired_at  ：取件码到期时间戳，永久地址为 0
     *
     * 注意：issueTimed 依赖真实 path/token 定位项目 secret，必须在改写前签发。
     *
     * @param int $ttl >0 时签发时限取件码
     */
    public static function publicRow(array $row, array $bucket, int $ttl = 0): array
    {
        $issued = ($ttl > 0 && self::isLocalBucket($bucket)) ? self::issueTimed($row, $ttl, $bucket) : [];

        $absolute = self::publicUrl($row, $bucket, true);
        $relative = self::publicPath($row, $bucket);
        if ($relative === '') {
            $relative = $absolute;
        }

        $row['path'] = $relative;
        $row['src'] = $relative;
        $row['surl'] = $relative;
        $row['url'] = $absolute;
        $row['file_url'] = $absolute;
        $row['file_expired_at'] = 0;
        if (!empty($issued)) {
            $row['file_url'] = $issued['url'];
            $row['file_expired_at'] = $issued['expired_at'];
        }
        return $row;
    }

    /** 取件码签名 */
    public static function sign(int $endTime, int $attachmentId, string $secret): string
    {
        return md5($endTime . '|' . $attachmentId . '|' . $secret);
    }

    /** 生成标准 base64 取件码（保留 + / = 填充，与既有格式一致） */
    public static function encodeCode(int $endTime, int $attachmentId, string $secret): string
    {
        $payload = $endTime . '|' . $attachmentId . '|' . self::sign($endTime, $attachmentId, $secret);
        return base64_encode($payload);
    }

    /**
     * 解析取件码；优先标准 base64，同时兼容 URL-safe（-_ 与无 = 填充）。
     *
     * @return array{end:int,id:int,sign:string}|null
     */
    public static function decodeCode(string $code): ?array
    {
        // URL 传输中 + 常被解码为空格（query/表单），先还原
        $code = str_replace(' ', '+', trim($code));
        if ($code === '' || strlen($code) > 256) {
            return null;
        }
        $normal = strtr($code, '-_', '+/');
        $normal .= str_repeat('=', (4 - strlen($normal) % 4) % 4);
        $raw = base64_decode($normal, true);
        if ($raw === false || preg_match('/^(\d{1,12})\|(\d{1,12})\|([0-9a-f]{32})$/', $raw, $m) !== 1) {
            return null;
        }
        return ['end' => (int)$m[1], 'id' => (int)$m[2], 'sign' => $m[3]];
    }

    /**
     * 签发给定时限地址。OSS 附件不签发（无法由 Nginx 控制访问）。
     *
     * $bucket 用于取该 Bucket 自己的域名；不传时回退全局 attach_domain。
     *
     * @param array $bucket ao_v3_storage 行，可为空
     * @return array{url:string,expired_at:int,expires_in:int} 失败返回 []
     */
    public static function issueTimed(array $row, int $ttl, array $bucket = []): array
    {
        $id = (int)($row['id'] ?? 0);
        $secret = V3ProjectModel::api_secret_by_attachment($row);
        if ($id <= 0 || $secret === '') {
            return [];
        }
        $maxTtl = max(1, (int)config('attach.max_ttl'));
        $ttl = max(1, min($ttl, $maxTtl));
        $end = time() + $ttl;
        $code = self::encodeCode($end, $id, $secret);
        $domain = self::bucketDomain($bucket);
        return [
            'url'        => ($domain !== '' ? $domain : '') . '/file/' . $code . self::urlSuffix($row),
            'expired_at' => $end,
            'expires_in' => $ttl,
        ];
    }

    /**
     * 附件对外地址：local 走虚拟分发地址，oss 保留原域名直链。
     *
     * @param bool $withDomain false 时仅返回相对路径（用于 src 字段）
     */
    public static function publicUrl(array $row, array $bucket, bool $withDomain = true): string
    {
        $path = self::normalizePath((string)($row['path'] ?? ''));
        if (!self::isLocalBucket($bucket)) {
            $base = rtrim((string)($bucket['url'] ?? ''), '/');
            return ($withDomain && $base !== '') ? $base . '/' . $path : $path;
        }
        // 待签发的数据可能只有 path（如上传回执），urlSuffix 会从 path 推断后缀。
        // 域名取该 Bucket 自己的配置，不同项目挂不同域名时返回地址才不会串站。
        $virtual = self::virtualUrl(
            self::bucketSegment($bucket),
            (string)($row['md5'] ?? ''),
            self::urlSuffix($row),
            self::bucketDomain($bucket)
        );
        if ($virtual === '' || !$withDomain) {
            return $path;
        }
        return $virtual;
    }

    public static function isLocalBucket(array $bucket): bool
    {
        return !empty($bucket) && strtolower((string)($bucket['type'] ?? 'local')) === 'local';
    }

    /** 取附件的安全相对路径；路径不合法返回空字符串（auth_request 回传路径用） */
    public static function safeRelativePath(array $row): string
    {
        $path = self::normalizePath((string)($row['path'] ?? ''));
        return self::isSafeRelativePath($path) ? $path : '';
    }

    /** Bucket 的落盘前缀必须与附件 path 一致，避免用别的 Bucket id 猜到同一 md5 */
    public static function pathBelongsToBucket(array $bucket, string $path): bool
    {
        $path = self::normalizePath($path);
        if (!self::isSafeRelativePath($path)) {
            return false;
        }
        $prefix = trim((string)($bucket['path'] ?? ''));
        if ($prefix === '') {
            $prefix = trim((string)($bucket['name'] ?? ''));
        }
        $prefix = self::normalizePath($prefix);
        return $prefix !== '' && str_starts_with($path, $prefix . '/');
    }

    /** 永久地址定位：Bucket path + md5 → 附件行（带分发缓存，见 cacheableRow） */
    public static function findForBucket(string $bucketPath, string $md5): array
    {
        $bucket = V3StorageModel::api_find_path($bucketPath);
        if (empty($bucket)) {
            return [];
        }
        $bucketId = (int)$bucket['id'];
        $md5 = strtolower(trim($md5));
        if (preg_match('/^[0-9a-f]{32}$/', $md5) !== 1) {
            return [];
        }

        // 分发高频路径：bucket_path + md5 → 真实落盘路径等最小字段走 Redis，
        // 避免 nginx auth_request 每次都扫 ao_attachment；失效靠 TTL。
        $ttl = self::deliverCacheTtl();
        $cacheKey = self::CACHE_PREFIX . 'v:' . $bucketPath . ':' . $md5;
        if ($ttl > 0) {
            $hit = Cache::store()->get($cacheKey);
            if (is_array($hit) && isset($hit['path'])) {
                return $hit;
            }
        }

        $token = V3StorageModel::api_group_key($bucketId);
        $query = Db::name('ao_attachment')
            ->where('md5', $md5)
            ->where('tag', 'v3')
            ->where('is_deleted', 0);
        if (preg_match('/^v3bucket:(\d+)$/', $token, $m) === 1) {
            if ((int)$m[1] !== $bucketId) {
                return [];
            }
        } else {
            $query->where('token', $token);
        }

        $rows = $query->select()->toArray();
        foreach ($rows as $row) {
            if (self::pathBelongsToBucket($bucket, (string)($row['path'] ?? ''))) {
                $cached = self::cacheableRow($row);
                if ($ttl > 0) {
                    Cache::store()->set($cacheKey, $cached, $ttl);
                }
                return $cached;
            }
        }
        return [];
    }

    /** 取件码定位：attachment.id → 附件行（带分发缓存，见 cacheableRow） */
    public static function findById(int $id): array
    {
        if ($id <= 0) {
            return [];
        }
        $ttl = self::deliverCacheTtl();
        $cacheKey = self::CACHE_PREFIX . 'c:' . $id;
        if ($ttl > 0) {
            $hit = Cache::store()->get($cacheKey);
            if (is_array($hit) && isset($hit['path'])) {
                return $hit;
            }
        }
        $row = Db::name('ao_attachment')
            ->where('id', $id)
            ->where('is_deleted', 0)
            ->whereRaw("(`tag` = 'v3' OR `token` LIKE 'v3bucket:%')")
            ->findOrEmpty();
        if (empty($row)) {
            return [];
        }
        $cached = self::cacheableRow($row);
        if ($ttl > 0) {
            Cache::store()->set($cacheKey, $cached, $ttl);
        }
        return $cached;
    }

    /**
     * 分发解析缓存：md5 / 取件码 → 真实落盘路径等最小字段。
     *
     * 只缓存分发链路（deliver / check）实际读取的字段，后续的
     * api_secret_by_attachment / bucketForRow 只用 token + path，
     * 也在缓存字段内。删除/换路径后最长滞后一个 TTL（默认 300s），
     * 本地文件删除后 is_file 兜底返回 404，可接受。
     */
    private static function cacheableRow(array $row): array
    {
        return [
            'id'         => (int)($row['id'] ?? 0),
            'md5'        => (string)($row['md5'] ?? ''),
            'token'      => (string)($row['token'] ?? ''),
            'path'       => (string)($row['path'] ?? ''),
            'ext'        => (string)($row['ext'] ?? ''),
            'name'       => (string)($row['name'] ?? ''),
            'is_deleted' => (int)($row['is_deleted'] ?? 0),
        ];
    }

    /** 分发解析缓存有效期（秒），0 关闭缓存 */
    private static function deliverCacheTtl(): int
    {
        return max(0, (int)config('attach.deliver_cache_ttl', 300));
    }

    /** 按附件的 token + path 反查所属 Bucket */
    public static function bucketForRow(array $row): array
    {
        $bucket = V3StorageProjectModel::api_match_by_path(
            (string)($row['token'] ?? ''),
            (string)($row['path'] ?? '')
        );
        if (!empty($bucket)) {
            return $bucket;
        }
        if (preg_match('/^v3bucket:(\d+)$/', (string)($row['token'] ?? ''), $m) === 1) {
            return V3StorageModel::api_find_id((int)$m[1]);
        }
        return [];
    }

    /**
     * 交付附件：仅设置响应头，把文件正文交给 Nginx。
     *
     * 绝不读取文件内容；$expiresAt 不为空时按剩余有效期设置缓存。
     */
    public static function deliver(array $row, array $bucket, bool $download = false, ?int $expiresAt = null): void
    {
        if ((int)($row['is_deleted'] ?? 0) !== 0) {
            self::abort(404, '附件不存在');
        }

        $path = self::normalizePath((string)($row['path'] ?? ''));
        if (!self::isSafeRelativePath($path)) {
            self::abort(404, '附件路径不合法');
        }

        // OSS 无法走 X-Accel-Redirect，永久地址继续跳转原直链。
        if (!self::isLocalBucket($bucket)) {
            $base = rtrim((string)($bucket['url'] ?? ''), '/');
            if ($base === '') {
                self::abort(404, '远程附件地址不可用');
            }
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            header('Location: ' . $base . '/' . $path, true, 302);
            exit;
        }

        $root = self::uploadRoot();
        if ($root === false) {
            self::abort(404, '文件根目录不可用');
        }
        $full = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path));
        if ($full === false || !str_starts_with($full, $root . DIRECTORY_SEPARATOR) || !is_file($full)) {
            self::abort(404, '文件不存在');
        }

        $uri = self::internalUri($path);
        if ($uri === '') {
            self::abort(500, '内部文件映射未配置');
        }

        $ext = strtolower((string)($row['ext'] ?? pathinfo($path, PATHINFO_EXTENSION)));
        $inline = !$download && isset(self::INLINE_TYPES[$ext]);
        $contentType = $inline ? self::INLINE_TYPES[$ext] : 'application/octet-stream';

        $name = (string)($row['name'] ?? '');
        if ($name === '') {
            $name = basename($path);
        }
        $name = preg_replace('/[^\w.\-]+/u', '_', $name) ?: 'download';
        $fallback = preg_replace('/[^\x20-\x7e]/', '_', $name) ?: 'download';
        $fallback = str_replace(['"', "\r", "\n"], '', $fallback);

        $maxAge = $expiresAt === null
            ? max(0, (int)config('attach.cache_ttl'))
            : max(0, $expiresAt - time());

        header('Content-Type: ' . $contentType);
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
            . '; filename="' . $fallback . '"'
            . "; filename*=UTF-8''" . rawurlencode($name));
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: public, max-age=' . $maxAge);
        // nginx 侧缓存窗口与取件码剩余有效期一致，避免过期后仍被反向代理命中
        header('X-Accel-Expires: ' . $maxAge);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('X-Accel-Redirect: ' . $uri);
        exit;
    }

    private static function normalizePath(string $path): string
    {
        return str_replace('\\', '/', trim($path));
    }

    private static function isSafeRelativePath(string $path): bool
    {
        if ($path === '' || $path[0] === '/' || str_contains($path, "\0")) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }
        return true;
    }

    private static function uploadRoot(): string|false
    {
        $configured = trim((string)config('attach.upload_root'));
        $root = $configured !== '' ? $configured : public_path() . 'upload';
        return realpath($root);
    }

    /** 生成 nginx internal location 使用的 URI，逐段做百分号编码 */
    private static function internalUri(string $path): string
    {
        $prefix = trim((string)config('attach.internal_prefix'));
        if ($prefix === '') {
            return '';
        }
        if ($prefix[0] !== '/') {
            $prefix = '/' . $prefix;
        }
        $prefix = rtrim($prefix, '/') . '/';
        $segments = array_map('rawurlencode', explode('/', $path));
        return $prefix . implode('/', $segments);
    }

    private static function abort(int $status, string $message): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo $message;
        exit;
    }
}
