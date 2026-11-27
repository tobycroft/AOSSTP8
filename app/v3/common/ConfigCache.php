<?php

namespace app\v3\common;

use think\facade\Cache;

/**
 * V3 配置类读缓存
 *
 * 项目凭证、Bucket、项目 ↔ Bucket 关联、对外域名等配置在请求间几乎不变，
 * 但每个上传请求都要重复回表，零碎文件高并发时 DB 往返会被放大。
 * 这里统一走 cache store（生产为 Redis）：命中直接返回，未命中回表后写入。
 *
 * 一致性策略：所有键带一个版本号前缀，后台修改配置后调用 flush() 切换版本，
 * 旧键随即失效（无需逐个枚举清理）；TTL 只作为漏清理时的兜底。
 *
 * 缓存不可用（Redis 抖动/未配置）时直接回表，不影响主流程。
 */
class ConfigCache
{
    /** 键前缀，避免与业务缓存键冲突 */
    private const PREFIX = 'v3cfg:';

    /** 版本号键：全局切换一次即让所有配置缓存失效 */
    private const VERSION_KEY = 'v3cfg:ver';

    /** 默认 TTL（秒）：跨进程漏清理时最多脏这么久 */
    public const TTL = 60;

    /** 单请求内版本号缓存，避免同一请求多次读取 */
    private static ?string $version = null;

    /**
     * 读缓存，未命中时执行 $loader 回表并写入
     *
     * @param string   $key    业务键（不含前缀）
     * @param callable $loader 回表闭包，返回需要缓存的值
     * @param int      $ttl    有效秒数
     */
    public static function remember(string $key, callable $loader, int $ttl = self::TTL): mixed
    {
        $cacheKey = self::PREFIX . self::version() . ':' . $key;

        try {
            $cached = Cache::get($cacheKey);
            // Redis 未命中返回 null；空数组是合法缓存值，需要区分
            if ($cached !== null && $cached !== false) {
                return $cached;
            }
        } catch (\Throwable $e) {
            return $loader();
        }

        $value = $loader();

        try {
            Cache::set($cacheKey, $value, $ttl);
        } catch (\Throwable $e) {
            // 写缓存失败不影响本次结果
        }

        return $value;
    }

    /**
     * 后台修改 V3 配置后调用：切换版本号，让全部旧键自然失效
     */
    public static function flush(): void
    {
        self::$version = null;
        try {
            Cache::set(self::VERSION_KEY, (string)microtime(true));
        } catch (\Throwable $e) {
            // 缓存不可用时无旧键可失效
        }
    }

    /**
     * 当前配置缓存版本号
     *
     * 首次使用时写入固定初值，避免并发首请求各自写入不同版本。
     */
    private static function version(): string
    {
        if (self::$version !== null) {
            return self::$version;
        }

        try {
            $version = Cache::get(self::VERSION_KEY);
            if ($version === null || $version === false || $version === '') {
                $version = '1';
                Cache::set(self::VERSION_KEY, $version);
            }
        } catch (\Throwable $e) {
            // 缓存不可用：所有请求都回表，版本号仅为占位
            $version = 'fallback';
        }

        return self::$version = (string)$version;
    }
}
