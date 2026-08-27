<?php

namespace app\admin\controller;

use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
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
        $email = $user['email'] ?? '-';
        $phone = $user['phone'] ?? '-';
        $isSuper = $user['is_super'] ? '是' : '否';

        $html = Layout::begin('控制台', null, 'console');
        $html .= <<<HTML
    <div class="welcome">欢迎回来，{$user['nickname']}</div>
    <div class="card">
        <h3>个人信息</h3>
        <div class="info-row"><span class="label">用户名</span><span class="value">{$user['username']}</span></div>
        <div class="info-row"><span class="label">昵称</span><span class="value">{$user['nickname']}</span></div>
        <div class="info-row"><span class="label">邮箱</span><span class="value">{$email}</span></div>
        <div class="info-row"><span class="label">手机</span><span class="value">{$phone}</span></div>
        <div class="info-row"><span class="label">超级管理员</span><span class="value">{$isSuper}</span></div>
    </div>
HTML;
        $html .= Layout::end();
        return response($html)->contentType('text/html');
    }
}