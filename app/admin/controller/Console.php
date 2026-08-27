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
        $email = $user['email'] ?? '-';
        $phone = $user['phone'] ?? '-';
        $isSuper = $user['is_super'] ? '是' : '否';
        $html = <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AOSS 控制台</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f0f2f5; }
.header { background: #001529; padding: 0 24px; height: 64px; display: flex; align-items: center; justify-content: space-between; color: #fff; }
.header h1 { font-size: 20px; font-weight: 500; }
.header .user-info { display: flex; align-items: center; gap: 16px; }
.header .user-info span { font-size: 14px; }
.header .btn-logout { padding: 6px 16px; background: #ff4d4f; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; }
.header .btn-logout:hover { background: #ff7875; }
.sidebar { width: 220px; background: #fff; min-height: calc(100vh - 64px); border-right: 1px solid #e8e8e8; padding: 16px 0; position: fixed; }
.sidebar .menu-item { padding: 12px 24px; cursor: pointer; color: #333; font-size: 14px; display: block; text-decoration: none; transition: all 0.3s; }
.sidebar .menu-item:hover { background: #f0f5ff; color: #1677ff; }
.sidebar .menu-item.active { background: #e6f4ff; color: #1677ff; border-right: 3px solid #1677ff; }
.main { margin-left: 220px; padding: 24px; }
.card { background: #fff; border-radius: 8px; padding: 24px; margin-bottom: 16px; box-shadow: 0 1px 4px rgba(0,0,0,0.05); }
.card h3 { margin-bottom: 16px; color: #333; font-size: 16px; }
.card p { color: #666; font-size: 14px; line-height: 1.8; }
.card .info-row { display: flex; margin-bottom: 8px; }
.card .info-row .label { width: 100px; color: #999; font-size: 14px; }
.card .info-row .value { color: #333; font-size: 14px; }
.welcome { font-size: 24px; font-weight: 500; color: #333; margin-bottom: 24px; }
</style>
</head>
<body>
<div class="header">
    <h1>AOSS 后台管理</h1>
    <div class="user-info">
        <span>欢迎，{$user['nickname']}</span>
        <button class="btn-logout" onclick="doLogout()">退出登录</button>
    </div>
</div>
<div class="sidebar">
    <a class="menu-item active" href="/admin/console">控制台</a>
    <a class="menu-item" href="/admin/user">用户管理</a>
    <a class="menu-item" href="/admin/role">角色管理</a>
    <a class="menu-item" href="/admin/menu">菜单管理</a>
</div>
<div class="main">
    <div class="welcome">欢迎回来，{$user['nickname']}</div>
    <div class="card">
        <h3>个人信息</h3>
        <div class="info-row"><span class="label">用户名</span><span class="value">{$user['username']}</span></div>
        <div class="info-row"><span class="label">昵称</span><span class="value">{$user['nickname']}</span></div>
        <div class="info-row"><span class="label">邮箱</span><span class="value">{$email}</span></div>
        <div class="info-row"><span class="label">手机</span><span class="value">{$phone}</span></div>
        <div class="info-row"><span class="label">超级管理员</span><span class="value">{$isSuper}</span></div>
    </div>
</div>
<script>
function doLogout() {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '/admin/login/logout', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.setRequestHeader('admin-token', localStorage.getItem('admin_token'));
    xhr.onreadystatechange = function() {
        if (xhr.readyState == 4) {
            localStorage.removeItem('admin_token');
            document.cookie = 'admin_token=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT';
            window.location.href = '/admin';
        }
    };
    xhr.send();
}
</script>
</body>
</html>
HTML;
        return response($html)->contentType('text/html');
    }
}