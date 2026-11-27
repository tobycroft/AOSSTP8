<?php

namespace app\admin\controller;

use app\admin\service\DashboardService;
use app\admin\utils\AdminAuth;
use BaseController\CommonController;

class Console extends CommonController
{
    public function initialize()
    {
        parent::initialize();
        AdminAuth::requireLogin();
    }

    public function index()
    {
        $user = AdminAuth::getLoginUser();
        $user['email'] = $user['email'] ?? '-';
        $user['phone'] = $user['phone'] ?? '-';
        $user['is_super_text'] = $user['is_super'] ? '是' : '否';

        // 缓存快照 5 分钟；?refresh=1 可强制重采（约 75 条统计 SQL，仅手工运维时使用）
        $stats = DashboardService::collect(input('get.refresh', 0, 'intval') === 1);

        return $this->renderPage('console/index', [
            'user' => $user,
            'dashboard' => $stats,
            'dashboardJson' => json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ], '控制台', null, 'console');
    }
}