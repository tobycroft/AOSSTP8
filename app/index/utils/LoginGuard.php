<?php

namespace app\index\utils;

use think\facade\Log;

/**
 * 登录失败守卫（门户 console 登录验证码策略）
 *
 * 规则：
 *   1. 同一 IP 在窗口期内登录失败 ≥ 1 次，后续登录必须输入验证码；
 *   2. 任意一次登录成功后立即清除该 IP 的失败状态，下次登录不再需要验证码；
 *   3. 失败状态有效期 2 小时，每次失败重新计时（滑动窗口）。
 *
 * 存储：Redis，键 aoss:login_fail:{md5(ip)}，值为失败次数。
 * 容错：Redis 未安装/不可用时降级为“不需要验证码”并写 warning 日志，
 *      避免缓存故障直接阻断正常登录（可用性优先）。
 */
class LoginGuard
{
    /** 失败状态有效期（秒），每次失败重新计时 */
    const TTL = 7200;

    /** Redis 键前缀 */
    const PREFIX = 'aoss:login_fail:';

    /** @var \Redis|null 请求级复用连接 */
    private static $handler = null;

    /** 是否已尝试过连接（避免同一请求重复连接） */
    private static $attempted = false;

    /** 本次请求是否已写过降级日志（避免刷日志） */
    private static $logged = false;

    /**
     * 生成指定 IP 的失败计数键
     */
    public static function key(string $ip): string
    {
        return self::PREFIX . md5($ip);
    }

    /**
     * 读取该 IP 的失败次数
     */
    public static function failCount(string $ip): int
    {
        $redis = self::redis();
        if (!$redis) {
            return 0;
        }
        try {
            return intval($redis->get(self::key($ip)));
        } catch (\Throwable $e) {
            self::degrade($e);
            return 0;
        }
    }

    /**
     * 该 IP 是否需要验证码
     */
    public static function needCaptcha(string $ip): bool
    {
        return self::failCount($ip) >= 1;
    }

    /**
     * 记录一次登录失败，返回累计失败次数
     */
    public static function markFail(string $ip): int
    {
        $redis = self::redis();
        if (!$redis) {
            return 0;
        }
        try {
            $key = self::key($ip);
            $count = intval($redis->incr($key));
            $redis->expire($key, self::TTL);
            return $count;
        } catch (\Throwable $e) {
            self::degrade($e);
            return 0;
        }
    }

    /**
     * 登录成功后清除该 IP 的失败状态
     */
    public static function clear(string $ip): void
    {
        $redis = self::redis();
        if (!$redis) {
            return;
        }
        try {
            $redis->del(self::key($ip));
        } catch (\Throwable $e) {
            self::degrade($e);
        }
    }

    /**
     * 获取 Redis 连接（延迟连接，失败返回 null）
     *
     * @return \Redis|null
     */
    private static function redis()
    {
        if (self::$attempted) {
            return self::$handler;
        }
        self::$attempted = true;

        if (!extension_loaded('redis')) {
            self::degrade(new \RuntimeException('未安装 redis 扩展'));
            return null;
        }

        try {
            $redis = new \Redis();
            $host = (string)env('REDIS_HOST', '127.0.0.1');
            $port = intval(env('REDIS_PORT', 6379));
            $timeout = floatval(env('REDIS_TIMEOUT', 0.5));
            if (!$redis->connect($host, $port, $timeout)) {
                self::degrade(new \RuntimeException('无法连接 ' . $host . ':' . $port));
                return null;
            }
            $password = (string)env('REDIS_PASSWORD', '');
            if ($password !== '') {
                $redis->auth($password);
            }
            $db = intval(env('REDIS_SELECT', 0));
            if ($db > 0) {
                $redis->select($db);
            }
            self::$handler = $redis;
        } catch (\Throwable $e) {
            self::degrade($e);
            return null;
        }

        return self::$handler;
    }

    /**
     * Redis 不可用时记录一次降级日志
     */
    private static function degrade(\Throwable $e): void
    {
        if (self::$logged) {
            return;
        }
        self::$logged = true;
        Log::warning('[LoginGuard] Redis 不可用，登录验证码策略降级（不强制验证码）：' . $e->getMessage());
    }
}
