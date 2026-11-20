<?php

namespace app\v3\common;

use think\facade\Cache;

/**
 * v3 开放接口固定窗口限流器（公共）
 *
 * 按项目凭证 kid 计数，同一应用（前缀）内各接口共用同一配额。
 * 计数存于限流专用 File 缓存 store（config/cache.php 的 limiter），
 * 与全局 CACHE_DRIVER 解耦：即使全局切到 Redis 且 Redis 不可用，
 * 限流也不会抛异常导致整个 v3 接口 500。
 *
 * 固定窗口：WINDOW 秒一个窗口，key 带窗口序号，窗口切换即重新计数。
 * 项目 rate_limit <= 0 表示不限速，直接放行（兼容未配置/未迁移的存量项目）。
 */
class RateLimiter
{
    /** 限流专用缓存 store（见 config/cache.php） */
    private const STORE = 'limiter';

    /** 固定窗口长度（秒） */
    public const WINDOW = 60;

    /** 计数键 TTL：略大于窗口，保证窗口内计数不丢，旧键随后自然过期 */
    private const TTL = self::WINDOW + 2;

    /**
     * 检查并累加一次请求计数；超限时输出 429 并终止请求。
     *
     * @param string $kid   项目凭证定位标识（鉴权后取得）
     * @param int    $limit 每窗口允许的请求次数，<=0 表示不限速
     * @param string $prefix 缓存 key 前缀，不同应用独立计数（如 v3_ai_rl: / v3_excel_rl:）
     */
    public static function check(string $kid, int $limit, string $prefix = 'v3_ai_rl:'): void
    {
        if ($limit <= 0) {
            return;
        }

        try {
            self::doCheck($kid, $limit, $prefix);
        } catch (\Throwable $e) {
            // 缓存自身异常时放行，避免限流组件故障导致整个 v3 接口不可用；
            // 这是可用性优先的降级策略，异常已记录便于排查。
            if (function_exists('trace')) {
                trace('RateLimiter 缓存异常，本次放行: ' . $e->getMessage(), 'error');
            }
        }
    }

    /**
     * 实际的固定窗口计数逻辑（缓存异常由 check 统一兜底）
     */
    private static function doCheck(string $kid, int $limit, string $prefix): void
    {
        $cache = Cache::store(self::STORE);
        $key = $prefix . $kid . ':' . intdiv(time(), self::WINDOW);
        $raw = $cache->get($key);

        // 窗口内首次请求：写入计数 1 并带 TTL 后放行
        if ($raw === null) {
            $cache->set($key, 1, self::TTL);
            return;
        }

        // 注意：不用 Cache::inc —— File 驱动对不存在的键会置 expire=0（永不过期）
        $count = (int)$raw;
        if ($count >= $limit) {
            $retry = self::WINDOW - (time() % self::WINDOW);
            if (!headers_sent()) {
                header('Retry-After: ' . $retry);
            }
            \Ret::Fail(429, [
                'limit' => $limit,
                'window' => self::WINDOW,
                'retry_after' => $retry,
            ], '请求过于频繁，请在 ' . $retry . ' 秒后重试');
        }

        $cache->set($key, $count + 1, self::TTL);
    }
}
