<?php

namespace app\admin\controller;

use app\admin\utils\AdminAuth;
use BaseController\CommonController;
use think\App;
use think\facade\Cache;
use think\facade\Db;

/**
 * 系统诊断：集中展示运行环境与各依赖组件的状态。
 *
 * 所有探测均为只读操作，且逐项捕获异常，避免单个组件故障导致页面无法渲染。
 * 敏感信息（密码）一律掩码，仅显示是否已配置。
 */
class Diagnose extends CommonController
{
    public function initialize()
    {
        parent::initialize();
        AdminAuth::requireLogin();
    }

    public function index()
    {
        $environment = $this->environment();
        $database = $this->database();
        $redis = $this->redis();
        $cache = $this->cache();
        $filesystem = $this->filesystem();

        $summary = [
            'php_version'   => PHP_VERSION,
            'database_ok'   => $database['ok'],
            'redis_ok'      => $redis['ok'],
            'cache_driver'  => $cache['rows'][0]['value'] ?? '-',
            'filesystem_ok' => $filesystem['ok'],
        ];

        return $this->renderPage('diagnose/index', [
            'summary'     => $summary,
            'environment' => $environment,
            'database'    => $database,
            'redis'       => $redis,
            'cache'       => $cache,
            'filesystem'  => $filesystem,
        ], '功能检测', 'diagnose', 'diagnose');
    }

    /**
     * 运行环境
     */
    private function environment(): array
    {
        return [
            'ok'   => true,
            'rows' => [
                ['label' => 'PHP 版本', 'value' => PHP_VERSION],
                ['label' => 'ThinkPHP 版本', 'value' => App::VERSION],
                ['label' => '操作系统', 'value' => PHP_OS . ' (' . php_uname('m') . ')'],
                ['label' => 'SAPI', 'value' => php_sapi_name()],
                ['label' => '内存限制', 'value' => ini_get('memory_limit')],
                ['label' => '最大执行时间', 'value' => ini_get('max_execution_time') . ' 秒'],
                ['label' => '默认时区', 'value' => date_default_timezone_get()],
                ['label' => '服务器时间', 'value' => date('Y-m-d H:i:s')],
                ['label' => '已加载扩展数', 'value' => count(get_loaded_extensions())],
            ],
        ];
    }

    /**
     * 数据库：连接配置 + 连通性 + 版本
     */
    private function database(): array
    {
        $rows = [];
        $ok = true;
        $error = '';

        try {
            $config = config('database');
            $default = $config['default'] ?? 'mysql';
            $conn = $config['connections'][$default] ?? [];

            $rows[] = ['label' => '连接标识', 'value' => (string) $default];
            $rows[] = ['label' => '驱动类型', 'value' => (string) ($conn['type'] ?? '-')];
            $rows[] = ['label' => '主机地址', 'value' => (string) ($conn['hostname'] ?? '-')];
            $rows[] = ['label' => '端口', 'value' => (string) ($conn['hostport'] ?? '-')];
            $rows[] = ['label' => '数据库名', 'value' => (string) ($conn['database'] ?? '-')];
            $rows[] = ['label' => '字符集', 'value' => (string) ($conn['charset'] ?? '-')];
            $rows[] = ['label' => '表前缀', 'value' => (string) ($conn['prefix'] ?? '-')];
            $rows[] = [
                'label'  => '密码',
                'value'  => !empty($conn['password']) ? '已设置（已隐藏）' : '未设置',
                'status' => !empty($conn['password']) ? 'ok' : 'warn',
            ];

            $start = microtime(true);
            $result = Db::query('SELECT 1 AS probe');
            $latency = round((microtime(true) - $start) * 1000, 1);
            $connected = isset($result[0]['probe']) && $result[0]['probe'] == 1;

            $rows[] = [
                'label'  => '连接状态',
                'value'  => $connected ? '正常' : '异常（探测未返回预期结果）',
                'status' => $connected ? 'ok' : 'fail',
            ];
            $rows[] = ['label' => '响应耗时', 'value' => $latency . ' ms'];

            if ($connected) {
                $version = Db::query('SELECT VERSION() AS version');
                $rows[] = ['label' => '数据库版本', 'value' => (string) ($version[0]['version'] ?? '-')];
            }
        } catch (\Throwable $e) {
            $ok = false;
            $error = $e->getMessage();
            $rows[] = ['label' => '连接状态', 'value' => '失败', 'status' => 'fail'];
        }

        return ['ok' => $ok, 'rows' => $rows, 'error' => $error];
    }

    /**
     * Redis：扩展 + 配置 + 连通性 + 版本 / key 数 / 内存
     */
    private function redis(): array
    {
        $rows = [];
        $error = '';

        $rows[] = [
            'label'  => 'phpredis 扩展',
            'value'  => extension_loaded('redis') ? '已安装' : '未安装',
            'status' => extension_loaded('redis') ? 'ok' : 'fail',
        ];

        $options = config('cache.stores.redis') ?: [];
        $host = (string) ($options['host'] ?? '127.0.0.1');
        $port = (int) ($options['port'] ?? 6379);
        $password = (string) ($options['password'] ?? '');
        $select = (int) ($options['select'] ?? 0);

        $rows[] = ['label' => '主机地址', 'value' => $host];
        $rows[] = ['label' => '端口', 'value' => (string) $port];
        $rows[] = ['label' => '库序号', 'value' => (string) $select];
        $rows[] = [
            'label'  => '密码',
            'value'  => $password !== '' ? '已设置（已隐藏）' : '未设置',
            'status' => $password !== '' ? 'ok' : 'warn',
        ];

        if (!extension_loaded('redis')) {
            return ['ok' => false, 'rows' => $rows, 'error' => '未安装 phpredis 扩展，无法连接 Redis'];
        }

        $timeout = (float) ($options['timeout'] ?? 0);
        if ($timeout <= 0) {
            $timeout = 1.0;
        }
        $timeout = min($timeout, 3.0);

        try {
            $client = new \Redis();
            $start = microtime(true);
            $client->connect($host, $port, $timeout);
            if ($password !== '') {
                $client->auth($password);
            }
            if ($select !== 0) {
                $client->select($select);
            }
            $latency = round((microtime(true) - $start) * 1000, 1);

            $pong = $client->ping();
            $connected = ($pong === true || $pong === 'PONG');

            $rows[] = [
                'label'  => '连接状态',
                'value'  => $connected ? '正常' : '异常（PING 未返回预期结果）',
                'status' => $connected ? 'ok' : 'fail',
            ];
            $rows[] = ['label' => '响应耗时', 'value' => $latency . ' ms'];

            if ($connected) {
                $info = $client->info();
                $rows[] = ['label' => 'Redis 版本', 'value' => (string) ($info['server']['redis_version'] ?? '-')];
                $rows[] = ['label' => '当前库 Key 数', 'value' => (string) $client->dbSize()];
                $rows[] = ['label' => '已用内存', 'value' => (string) ($info['memory']['used_memory_human'] ?? '-')];
                $rows[] = ['label' => '运行模式', 'value' => (string) ($info['server']['redis_mode'] ?? '-')];
            }
        } catch (\Throwable $e) {
            $rows[] = ['label' => '连接状态', 'value' => '失败', 'status' => 'fail'];
            $error = $e->getMessage();
        }

        return ['ok' => $error === '', 'rows' => $rows, 'error' => $error];
    }

    /**
     * 缓存配置概览
     */
    private function cache(): array
    {
        $config = config('cache') ?: [];
        $stores = $config['stores'] ?? [];

        $rows = [
            ['label' => '当前默认驱动', 'value' => (string) ($config['default'] ?? '-')],
            ['label' => '缓存前缀', 'value' => (string) env('CACHE_PREFIX', '')],
            ['label' => '默认有效期', 'value' => (string) env('CACHE_EXPIRE', 0) . ' 秒（0 为永久）'],
        ];

        $types = [];
        foreach ($stores as $name => $store) {
            $types[] = $name . '(' . ($store['type'] ?? '-') . ')';
        }
        $rows[] = ['label' => '已配置存储', 'value' => $types ? implode('、', $types) : '-'];

        try {
            $rows[] = ['label' => '实际驱动类', 'value' => get_class(Cache::store())];
        } catch (\Throwable $e) {
            $rows[] = ['label' => '实际驱动类', 'value' => '获取失败：' . $e->getMessage(), 'status' => 'fail'];
        }

        return ['ok' => true, 'rows' => $rows];
    }

    /**
     * 目录权限与磁盘空间
     */
    private function filesystem(): array
    {
        $root = root_path();
        $dirs = [
            'runtime'       => $root . 'runtime',
            'runtime/cache' => $root . 'runtime' . DIRECTORY_SEPARATOR . 'cache',
            'public'        => $root . 'public',
        ];

        $rows = [];
        $allOk = true;
        foreach ($dirs as $label => $path) {
            $exists = is_dir($path);
            $writable = $exists && is_writable($path);
            if (!$writable) {
                $allOk = false;
            }
            $rows[] = [
                'label'  => $label . ' 可写',
                'value'  => !$exists ? '目录不存在' : ($writable ? '是' : '否'),
                'status' => $writable ? 'ok' : 'fail',
            ];
        }

        $free = @disk_free_space($root);
        $total = @disk_total_space($root);
        if ($free !== false && $total !== false) {
            $rows[] = ['label' => '磁盘可用空间', 'value' => $this->formatBytes((float) $free) . ' / ' . $this->formatBytes((float) $total)];
            $rows[] = [
                'label'  => '磁盘使用率',
                'value'  => round((1 - $free / $total) * 100, 1) . '%',
                'status' => ($free / $total) < 0.1 ? 'warn' : 'ok',
            ];
        }

        return ['ok' => $allOk, 'rows' => $rows];
    }

    private function formatBytes(float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
