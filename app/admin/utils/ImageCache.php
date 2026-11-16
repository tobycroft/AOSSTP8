<?php

namespace app\admin\utils;

use think\Exception;

/**
 * 后台图片测试 - 本地缓存
 *
 * 上游图片接口是同步的且生成较慢（可达 1-2 分钟），故把结果按「参数指纹」落到
 * public/ai/ 下：TTL 内命中直接返回本地 URL（秒回、不请求上游），TTL 到期后由
 * 下一次请求触发重新生成并覆盖。项目无 cron / 队列，采用惰性过期刷新。
 *
 * 目录结构（key 为 sha256，仅含 0-9a-f，无路径穿越风险）：
 *   public/ai/{key}.png     图片本体
 *   public/ai/{key}.json    元信息（本地写入时间、TTL、上游地址等）
 *   runtime/ai_cache_lock/{key}.lock  并发锁（避免惊群重复调用上游）
 */
class ImageCache
{
    /** 默认缓存 24 小时；图片生成慢且可能计费，缓存久一些更有价值 */
    public const DEFAULT_TTL = 86400;
    public const MIN_TTL = 60;
    public const MAX_TTL = 2592000; // 30 天

    /** 落盘允许的扩展名白名单，默认 png */
    private const EXTS = ['png', 'jpg', 'jpeg', 'webp', 'gif', 'bmp'];
    /** 单张图片下载上限 50MB，防止异常响应撑爆磁盘 */
    private const MAX_BYTES = 52428800;

    /**
     * 规整 TTL（秒）：非正数用默认值，越界收敛到 [MIN_TTL, MAX_TTL]
     */
    public static function normalizeTtl($raw): int
    {
        $ttl = (int)$raw;
        if ($ttl <= 0) {
            return self::DEFAULT_TTL;
        }
        return max(self::MIN_TTL, min(self::MAX_TTL, $ttl));
    }

    /**
     * 参数指纹：任何影响生成结果的参数都要纳入，否则会串图。
     */
    public static function key(int $id, array $params): string
    {
        $material = [
            'project' => $id,
            'prompt' => (string)($params['prompt'] ?? ''),
            'size' => (string)($params['size'] ?? ''),
            'ratio' => (string)($params['ratio'] ?? ''),
            'response_format' => (string)($params['response_format'] ?? ''),
            'image' => $params['image'] ?? [],
        ];
        return hash('sha256', json_encode($material, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function dir(): string
    {
        $dir = public_path() . 'ai' . DIRECTORY_SEPARATOR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    private static function lockDir(): string
    {
        $dir = app()->getRuntimePath() . 'ai_cache_lock' . DIRECTORY_SEPARATOR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * 取独占锁；调用方用完后必须 releaseLock()
     */
    public static function getLock(string $key)
    {
        $fp = @fopen(self::lockDir() . $key . '.lock', 'c');
        if ($fp !== false) {
            flock($fp, LOCK_EX);
        }
        return $fp;
    }

    public static function releaseLock($fp): void
    {
        if (is_resource($fp)) {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    private static function metaPath(string $key): string
    {
        return self::dir() . $key . '.json';
    }

    private static function readMeta(string $key): ?array
    {
        $path = self::metaPath($key);
        if (!is_file($path)) {
            return null;
        }
        $meta = json_decode((string)file_get_contents($path), true);
        return is_array($meta) ? $meta : null;
    }

    private static function findExt(string $key): ?string
    {
        $meta = self::readMeta($key);
        if ($meta && !empty($meta['ext']) && in_array($meta['ext'], self::EXTS, true)) {
            if (is_file(self::dir() . $key . '.' . $meta['ext'])) {
                return $meta['ext'];
            }
        }
        foreach (self::EXTS as $ext) {
            if (is_file(self::dir() . $key . '.' . $ext)) {
                return $ext;
            }
        }
        return null;
    }

    public static function publicUrl(string $key, string $ext): string
    {
        return '/ai/' . $key . '.' . $ext;
    }

    /**
     * 读取未过期缓存。命中返回组装好的响应数据，否则清理旧文件并返回 null。
     */
    public static function get(string $key, int $ttl): ?array
    {
        $meta = self::readMeta($key);
        if (!$meta) {
            return null;
        }
        $createdAt = (int)($meta['created_at'] ?? 0);
        if ($createdAt <= 0 || time() - $createdAt >= $ttl) {
            self::clear($key);
            return null;
        }
        $ext = self::findExt($key);
        if (!$ext) {
            self::clear($key);
            return null;
        }

        return [
            'url' => self::publicUrl($key, $ext),
            'source_url' => $meta['source_url'] ?? null,
            'created' => $meta['created'] ?? null,
            'cache_hit' => true,
            'cache' => [
                'hit' => true,
                'path' => 'public/ai/' . $key . '.' . $ext,
                'created_at' => $createdAt,
                'expires_at' => $createdAt + $ttl,
                'ttl' => $ttl,
                'age' => time() - $createdAt,
            ],
        ];
    }

    /**
     * 上游响应落盘并写元信息，返回统一响应数据。
     */
    public static function put(string $key, array $upstream, array $context, int $ttl): array
    {
        $first = $upstream['data'][0] ?? [];
        $sourceUrl = $first['url'] ?? null;
        $b64 = $first['b64_json'] ?? null;
        if (!$sourceUrl && !$b64) {
            throw new Exception('上游未返回图片数据（url / b64_json 均为空）');
        }

        if ($b64) {
            $ext = 'png';
            $dest = self::dir() . $key . '.png';
            $bytes = base64_decode((string)$b64, true);
            if ($bytes === false || $bytes === '') {
                throw new Exception('Base64 图片数据无效');
            }
            self::writeAtomic($dest, $bytes);
        } else {
            $ext = self::extFromUrl((string)$sourceUrl);
            $dest = self::dir() . $key . '.' . $ext;
            self::download((string)$sourceUrl, $dest);
        }

        $now = time();
        self::writeMeta($key, [
            'created_at' => $now,
            'ttl' => $ttl,
            'ext' => $ext,
            'source_url' => $sourceUrl,
            'created' => $upstream['created'] ?? null,
            'prompt' => $context['prompt'] ?? '',
            'size' => $context['size'] ?? '',
            'ratio' => $context['ratio'] ?? '',
            'response_format' => $context['response_format'] ?? '',
        ]);

        return [
            'url' => self::publicUrl($key, $ext),
            'source_url' => $sourceUrl,
            'created' => $upstream['created'] ?? null,
            'cache_hit' => false,
            'cache' => [
                'hit' => false,
                'path' => 'public/ai/' . $key . '.' . $ext,
                'created_at' => $now,
                'expires_at' => $now + $ttl,
                'ttl' => $ttl,
            ],
        ];
    }

    private static function extFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $ext = $path ? strtolower(pathinfo((string)$path, PATHINFO_EXTENSION)) : '';
        return in_array($ext, self::EXTS, true) ? $ext : 'png';
    }

    /**
     * 下载上游图片到临时文件后原子改名，避免半截文件被命中。
     */
    private static function download(string $url, string $dest): void
    {
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new Exception('上游图片地址协议不支持');
        }

        $tmp = $dest . '.tmp.' . getmypid();
        $fp = @fopen($tmp, 'wb');
        if (!$fp) {
            throw new Exception('无法写入缓存临时文件，请检查 public/ai 目录权限');
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function ($ch, $dlTotal, $dlNow) {
            return $dlNow > self::MAX_BYTES ? 1 : 0;
        });

        $ok = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        fclose($fp);

        if ($ok === false || $err !== '' || $code < 200 || $code >= 300) {
            @unlink($tmp);
            throw new Exception('下载上游图片失败' . ($err ? '：' . $err : '（HTTP ' . $code . '）'));
        }
        if (!filesize($tmp) || filesize($tmp) < 16) {
            @unlink($tmp);
            throw new Exception('上游图片内容为空或异常');
        }
        rename($tmp, $dest);
    }

    private static function writeAtomic(string $dest, string $bytes): void
    {
        $tmp = $dest . '.tmp.' . getmypid();
        $fp = @fopen($tmp, 'wb');
        if (!$fp) {
            throw new Exception('无法写入缓存临时文件，请检查 public/ai 目录权限');
        }
        $written = fwrite($fp, $bytes);
        fclose($fp);
        if ($written === false || $written !== strlen($bytes)) {
            @unlink($tmp);
            throw new Exception('缓存文件写入不完整');
        }
        rename($tmp, $dest);
    }

    private static function writeMeta(string $key, array $meta): void
    {
        self::writeAtomic(self::metaPath($key), json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * 清理单个缓存项（过期或图片缺失时调用）
     */
    public static function clear(string $key): void
    {
        @unlink(self::metaPath($key));
        foreach (self::EXTS as $ext) {
            @unlink(self::dir() . $key . '.' . $ext);
        }
    }
}
