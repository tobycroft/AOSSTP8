<?php

// +----------------------------------------------------------------------
// | 缓存设置
// +----------------------------------------------------------------------

return [
    // 默认缓存驱动，可通过 .env 的 CACHE_DRIVER 切换（file / redis）
    'default' => env('CACHE_DRIVER', 'file'),

    // 缓存连接方式配置
    'stores'  => [
        'file' => [
            // 驱动方式
            'type'       => 'File',
            // 缓存保存目录
            'path'       => '',
            // 缓存前缀
            'prefix'     => env('CACHE_PREFIX', ''),
            // 缓存有效期 0表示永久缓存
            'expire'     => env('CACHE_EXPIRE', 0),
            // 缓存标签前缀
            'tag_prefix' => 'tag:',
            // 序列化机制 例如 ['serialize', 'unserialize']
            'serialize'  => [],
        ],
        // v3 接口限流专用缓存：固定使用 File 驱动，与全局 CACHE_DRIVER 解耦。
        // 目的：限流不依赖 Redis 等外部服务，Redis 抖动时不会让 v3 接口整体 500。
        // 独立前缀避免与业务缓存键冲突；key 形如 v3rl:{prefix}{kid}:{窗口序号}。
        'limiter' => [
            'type'       => 'File',
            'path'       => '',
            'prefix'     => 'v3rl:',
            'expire'     => 0,
            'tag_prefix' => 'tag:',
            'serialize'  => [],
        ],
        // Redis 缓存（当 CACHE_DRIVER=redis 时启用）
        'redis' => [
            // 驱动方式
            'type'       => 'Redis',
            // 服务器地址
            'host'       => env('REDIS_HOST', '127.0.0.1'),
            // 端口
            'port'       => env('REDIS_PORT', '6379'),
            // 密码（留空表示无密码）
            'password'   => env('REDIS_PASSWORD', ''),
            // 库序号（建议指定独立库，避免与其他应用共用）
            'select'     => env('REDIS_SELECT', 0),
            // 连接超时（秒）
            'timeout'    => env('REDIS_TIMEOUT', 0),
            // 是否使用长连接
            'persistent' => env('CACHE_REDIS_PERSISTENT', false),
            // 缓存有效期 0表示永久缓存
            'expire'     => env('CACHE_EXPIRE', 0),
            // 缓存前缀（共享同一 Redis 库时务必区分）
            'prefix'     => env('CACHE_PREFIX', ''),
            // 缓存标签前缀
            'tag_prefix' => 'tag:',
            // 序列化机制
            'serialize'  => [],
        ],
        // 更多的缓存连接
    ],
];
