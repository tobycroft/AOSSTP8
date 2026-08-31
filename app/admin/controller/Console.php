<?php

namespace app\admin\controller;

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

        return $this->renderPage('console/index', ['user' => $user], '控制台', null, 'console');
    }
}