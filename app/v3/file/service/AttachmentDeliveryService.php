<?php

namespace app\v3\file\service;

use app\v1\system\model\SystemParamModel;
use app\v3\project\model\V3ProjectModel;
use app\v3\storage\model\V3StorageModel;
use app\v3\storage\model\V3StorageProjectModel;
use think\facade\Db;
use Throwable;

/**
 * V3 附件分发服务
 *
 * PHP 只做鉴权、元数据查询和响应头设置，文件正文由 Nginx 通过
 * X-Accel-Redirect 直接读取磁盘输出。本类禁止出现 readfile /
 * file_get_contents / fopen 循环输出等任何读取文件正文的操作。
 *
 * 两种入口：
 *   虚拟地址：{domain}/attachment/{bucket_path}/{md5}   —— 永久地址，按 Bucket path + MD5 定位
 *   时限地址：{domain}/file/{code}                      —— 取件码，含到期时间与签名
 *
 * 取件码格式：base64("{end_time}|{attachment_id}|{md5(end_time|id|secret)}")
 * 使用标准 Base64（保留 + / = 填充），与既有取件码格式一致。
 * secret 取附件所属项目的 ao_v3_project.secret，不随取件码下发。
 */
final class AttachmentDeliveryService
{
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
     * 对外域名前缀：system_param.attach_domain 优先，.env 仅作本地兜底。
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

    /** Bucket 的对外路径段：path 优先，留空回退 name */
    public static function bucketSegment(array $bucket): string
    {
        $segment = trim((string)($bucket['path'] ?? ''));
        if ($segment === '') {
            $segment = trim((string)($bucket['name'] ?? ''));
        }
        return $segment;
    }

    /** 永久虚拟地址（本地 Bucket 专用）：/attachment/{bucket_path}/{md5} */
    public static function virtualUrl(string $bucketPath, string $md5): string
    {
        $bucketPath = trim($bucketPath, '/');
        if ($bucketPath === '' || preg_match('/^[A-Za-z0-9._\-]+$/', $bucketPath) !== 1
            || preg_match('/^[0-9a-f]{32}$/', $md5) !== 1) {
            return '';
        }
        $domain = self::domain();
        return $domain . '/attachment/' . $bucketPath . '/' . strtolower($md5);
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
     * @return array{url:string,expired_at:int,expires_in:int} 失败返回 []
     */
    public static function issueTimed(array $row, int $ttl): array
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
        $domain = self::domain();
        return [
            'url'        => ($domain !== '' ? $domain : '') . '/file/' . $code,
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
        $virtual = self::virtualUrl(self::bucketSegment($bucket), (string)($row['md5'] ?? ''));
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

    /** 永久地址定位：Bucket path + md5 → 附件行 */
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
                return $row;
            }
        }
        return [];
    }

    /** 取件码定位：attachment.id → 附件行 */
    public static function findById(int $id): array
    {
        if ($id <= 0) {
            return [];
        }
        return Db::name('ao_attachment')
            ->where('id', $id)
            ->where('is_deleted', 0)
            ->whereRaw("(`tag` = 'v3' OR `token` LIKE 'v3bucket:%')")
            ->findOrEmpty();
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
