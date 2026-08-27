<?php

namespace app\admin\controller;

use app\admin\model\AdminCertLogModel;
use app\admin\utils\AdminAuth;
use BaseController\CommonController;

class CertLog extends CommonController
{
    public function initialize()
    {
        parent::initialize();
        AdminAuth::requireLogin();
    }

    public function index()
    {
        $method = request()->method();
        if ($method !== 'GET') {
            \Ret::Fail(405, null, '不支持的请求方法');
        }
        return $this->page();
    }

    private function page()
    {
        $page = input('get.page', 1, 'intval');
        $limit = 20;

        $model = new AdminCertLogModel();
        $list = $model->order('id', 'desc')->paginate($limit, false, ['page' => $page]);

        $currentPage = (int)$list->currentPage();
        $total = $list->total();
        $totalPages = max(1, (int)ceil($total / $limit));

        $html = <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AOSS 操作日志</title>
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
.sidebar .menu-item { padding: 12px 24px; cursor: pointer; color: #333; font-size: 14px; display: block; text-decoration: none; }
.sidebar .menu-item:hover { background: #f0f5ff; color: #1677ff; }
.sidebar .menu-item.active { background: #e6f4ff; color: #1677ff; border-right: 3px solid #1677ff; }
.sidebar .menu-group { }
.sidebar .menu-group-title { padding: 12px 24px; cursor: pointer; color: #333; font-size: 14px; display: flex; align-items: center; justify-content: space-between; user-select: none; }
.sidebar .menu-group-title:hover { background: #f0f5ff; color: #1677ff; }
.sidebar .menu-group-title .arrow { transition: transform 0.2s; font-size: 10px; }
.sidebar .menu-group-title.open .arrow { transform: rotate(90deg); }
.sidebar .menu-group-items { display: none; }
.sidebar .menu-group-items.open { display: block; }
.sidebar .menu-group-items .menu-item { padding-left: 44px; }
.main { margin-left: 220px; padding: 24px; }
table { width: 100%; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,0.05); border-collapse: collapse; }
th, td { padding: 12px 16px; text-align: left; border-bottom: 1px solid #f0f0f0; font-size: 14px; }
th { background: #fafafa; color: #666; font-weight: 500; }
td { color: #333; }
tr:hover { background: #fafafa; }
.pagination { margin-top: 16px; display: flex; justify-content: center; gap: 8px; }
.pagination a { padding: 6px 12px; border: 1px solid #d9d9d9; border-radius: 4px; color: #333; text-decoration: none; font-size: 14px; }
.pagination a:hover { border-color: #1677ff; color: #1677ff; }
.pagination a.active { background: #1677ff; color: #fff; border-color: #1677ff; }
.status-badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
.status-badge.success { background: #f6ffed; color: #52c41a; border: 1px solid #b7eb8f; }
.status-badge.fail { background: #fff2f0; color: #ff4d4f; border: 1px solid #ffccc7; }
.recv-cell { max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; cursor: pointer; }
.recv-cell:hover { color: #1677ff; }
</style>
</head>
<body>
<div class="header">
    <h1>AOSS 后台管理</h1>
    <div class="user-info">
        <span>欢迎，{AdminAuth::getLoginUser()['nickname']}</span>
        <button class="btn-logout" onclick="location.href='/admin/console'">返回控制台</button>
    </div>
</div>
<div class="sidebar">
    <a class="menu-item" href="/admin/console">控制台</a>
    <div class="menu-group">
        <div class="menu-group-title" onclick="toggleGroup(this)">
            <span>管理员</span>
            <span class="arrow">></span>
        </div>
        <div class="menu-group-items">
            <a class="menu-item" href="/admin/user">用户管理</a>
            <a class="menu-item" href="/admin/role">角色管理</a>
            <a class="menu-item" href="/admin/menu">菜单管理</a>
        </div>
    </div>
    <div class="menu-group">
        <div class="menu-group-title open" onclick="toggleGroup(this)">
            <span>证书管理</span>
            <span class="arrow">></span>
        </div>
        <div class="menu-group-items open">
            <a class="menu-item" href="/admin/cert">证书项目</a>
            <a class="menu-item" href="/admin/cert_url">证书URL</a>
            <a class="menu-item" href="/admin/cert_website">证书站点</a>
            <a class="menu-item active" href="/admin/cert_log">操作日志</a>
        </div>
    </div>
</div>
<div class="main">
    <h2 style="margin-bottom:16px;">操作日志</h2>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>AppName</th>
                <th>类型</th>
                <th>站点</th>
                <th>结果</th>
                <th>返回内容</th>
                <th>时间</th>
            </tr>
        </thead>
        <tbody>
HTML;
        foreach ($list as $item) {
            $resultBadge = $item['success'] == 1
                ? '<span class="status-badge success">成功</span>'
                : '<span class="status-badge fail">失败</span>';
            $recv = mb_strlen($item['recv']) > 60 ? mb_substr($item['recv'], 0, 60) . '...' : $item['recv'];
            $html .= <<<ROW
            <tr>
                <td>{$item['id']}</td>
                <td>{$item['appname']}</td>
                <td>{$item['type']}</td>
                <td>{$item['website']}</td>
                <td>{$resultBadge}</td>
                <td class="recv-cell" title="{$item['recv']}">{$recv}</td>
                <td>{$item['create_time']}</td>
            </tr>
ROW;
        }
        $html .= <<<HTML
        </tbody>
    </table>
    <div class="pagination">
HTML;
        for ($i = 1; $i <= $totalPages; $i++) {
            $active = $i == $currentPage ? ' class="active"' : '';
            $html .= "<a href=\"/admin/cert_log?page={$i}\"{$active}>{$i}</a>";
        }
        $html .= <<<HTML
    </div>
</div>
<script>
function toggleGroup(el) {
    el.classList.toggle('open');
    el.nextElementSibling.classList.toggle('open');
}
</script>
</body>
</html>
HTML;
        return response($html)->contentType('text/html');
    }
}