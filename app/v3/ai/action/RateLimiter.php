<?php

namespace app\v3\ai\action;

use think\facade\Cache;

/**
 * v3 AI 接口固定窗口限流器
 *
 * 按项目凭证 kid 计数，四个 AI 接口（chat/responses/messages/images）共用同一配额。
 * 计数存于 TP 自带 Cache（默认 file 驱动），不引入 Redis 依赖。
 *
 * 固定窗口：WINDOW 秒一个窗口，key 带窗口序号，窗口切换即重新计数。
 * 项目 rate_limit <= 0 表示不限速，直接放行（兼容未配置/未迁移的存量项目）。
 */
class RateLimiter
{
    /** 固定窗口长度（秒） */
    public const WINDOW = 60;

    /** 缓存 key 前缀，避免与其它业务计数冲突 */
    private const PREFIX = 'v3_ai_rl:';

    /** 计数键 TTL：略大于窗口，保证窗口内计数不丢，旧键随后自然过期 */
    private const TTL = self::WINDOW + 2;

    /**
     * 检查并累加一次请求计数；超限时输出 429 并终止请求。
     *
     * @param string $kid   项目凭证定位标识（鉴权后取得）
     * @param int    $limit 每窗口允许的请求次数，<=0 表示不限速
     */
    public static function check(string $kid, int $limit): void
    {
        if ($limit <= 0) {
            return;
        }

        $key = self::PREFIX . $kid . ':' . intdiv(time(), self::WINDOW);
        $raw = Cache::get($key);

        // 窗口内首次请求：写入计数 1 并带 TTL 后放行
        if ($raw === null) {
            Cache::set($key, 1, self::TTL);
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

        Cache::set($key, $count + 1, self::TTL);
    }
}
