<?php

namespace app\admin\controller;

use app\admin\model\AdminCertWebsiteModel;
use app\admin\utils\AdminAuth;
use BaseController\CommonController;
use Input;
use Ret;

class CertWebsite extends CommonController
{
    public function initialize()
    {
        parent::initialize();
        AdminAuth::requireLogin();
    }

    public function index()
    {
        $method = request()->method();
        switch ($method) {
            case 'GET':
                return $this->page();
            case 'POST':
                return $this->update();
            case 'PUT':
                return $this->create();
            case 'DELETE':
                return $this->delete();
        }
        Ret::Fail(405, null, '不支持的请求方法');
    }

    private function page()
    {
        $page = input('get.page', 1, 'intval');
        $limit = 15;

        $model = new AdminCertWebsiteModel();
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
<title>AOSS 证书站点</title>
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
.toolbar { margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; }
.btn { padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; }
.btn-primary { background: #1677ff; color: #fff; }
.btn-primary:hover { background: #4096ff; }
.btn-sm { padding: 4px 10px; font-size: 12px; }
.btn-edit { background: #1677ff; color: #fff; margin-right: 4px; }
.btn-edit:hover { background: #4096ff; }
.btn-del { background: #ff4d4f; color: #fff; }
.btn-del:hover { background: #ff7875; }
table { width: 100%; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,0.05); border-collapse: collapse; }
th, td { padding: 12px 16px; text-align: left; border-bottom: 1px solid #f0f0f0; font-size: 14px; }
th { background: #fafafa; color: #666; font-weight: 500; }
td { color: #333; }
tr:hover { background: #fafafa; }
.pagination { margin-top: 16px; display: flex; justify-content: center; gap: 8px; }
.pagination a { padding: 6px 12px; border: 1px solid #d9d9d9; border-radius: 4px; color: #333; text-decoration: none; font-size: 14px; }
.pagination a:hover { border-color: #1677ff; color: #1677ff; }
.pagination a.active { background: #1677ff; color: #fff; border-color: #1677ff; }
.modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.4); z-index: 1000; }
.modal.show { display: flex; align-items: center; justify-content: center; }
.modal-box { background: #fff; border-radius: 8px; padding: 24px; width: 520px; max-width: 90%; }
.modal-box h3 { margin-bottom: 20px; color: #333; }
.form-group { margin-bottom: 14px; }
.form-group label { display: block; margin-bottom: 4px; color: #555; font-size: 13px; }
.form-group input, .form-group select { width: 100%; padding: 8px 10px; border: 1px solid #d9d9d9; border-radius: 4px; font-size: 14px; outline: none; }
.form-group input:focus, .form-group select:focus { border-color: #4096ff; }
.modal-actions { text-align: right; margin-top: 20px; }
.modal-actions .btn { margin-left: 8px; }
.btn-cancel { background: #fff; color: #333; border: 1px solid #d9d9d9; }
.btn-cancel:hover { border-color: #1677ff; color: #1677ff; }
.status-badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
.status-badge.active { background: #f6ffed; color: #52c41a; border: 1px solid #b7eb8f; }
.status-badge.inactive { background: #fff2f0; color: #ff4d4f; border: 1px solid #ffccc7; }
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
            <a class="menu-item active" href="/admin/cert_website">证书站点</a>
            <a class="menu-item" href="/admin/cert_log">操作日志</a>
        </div>
    </div>
</div>
<div class="main">
    <div class="toolbar">
        <h2>证书站点</h2>
        <button class="btn btn-primary" onclick="openCreate()">新增站点</button>
    </div>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>证书名称</th>
                <th>站点</th>
                <th>类型</th>
                <th>API</th>
                <th>状态</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
HTML;
        foreach ($list as $item) {
            $statusBadge = $item['status'] == 1
                ? '<span class="status-badge active">启用</span>'
                : '<span class="status-badge inactive">禁用</span>';
            $html .= <<<ROW
            <tr>
                <td>{$item['id']}</td>
                <td>{$item['cert_name']}</td>
                <td>{$item['website']}</td>
                <td>{$item['type']}</td>
                <td style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{$item['api']}</td>
                <td>{$statusBadge}</td>
                <td>
                    <button class="btn btn-sm btn-edit" onclick="openEdit({$item['id']}, '{$item['cert_name']}', '{$item['website']}', '{$item['type']}', '{$item['api']}', '{$item['key']}', {$item['status']})">编辑</button>
                    <button class="btn btn-sm btn-del" onclick="doDelete({$item['id']})">删除</button>
                </td>
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
            $html .= "<a href=\"/admin/cert_website?page={$i}\"{$active}>{$i}</a>";
        }
        $html .= <<<HTML
    </div>
</div>

<div class="modal" id="websiteModal">
    <div class="modal-box">
        <h3 id="modalTitle">新增站点</h3>
        <input type="hidden" id="editId" value="">
        <div class="form-group">
            <label>证书名称</label>
            <input type="text" id="formCertName" placeholder="请输入证书名称">
        </div>
        <div class="form-group">
            <label>站点</label>
            <input type="text" id="formWebsite" placeholder="example.com">
        </div>
        <div class="form-group">
            <label>类型</label>
            <select id="formType">
                <option value="web">web</option>
                <option value="mail">mail</option>
                <option value="panel">panel</option>
            </select>
        </div>
        <div class="form-group">
            <label>API</label>
            <input type="text" id="formApi" placeholder="BT API 地址">
        </div>
        <div class="form-group">
            <label>Key</label>
            <input type="text" id="formKey" placeholder="BT Key">
        </div>
        <div class="form-group">
            <label>状态</label>
            <select id="formStatus">
                <option value="1">启用</option>
                <option value="0">禁用</option>
            </select>
        </div>
        <div class="modal-actions">
            <button class="btn btn-cancel" onclick="closeModal()">取消</button>
            <button class="btn btn-primary" onclick="submitForm()">确定</button>
        </div>
    </div>
</div>

<script>
function toggleGroup(el) {
    el.classList.toggle('open');
    el.nextElementSibling.classList.toggle('open');
}
function closeModal() {
    document.getElementById('websiteModal').classList.remove('show');
}
function openCreate() {
    document.getElementById('modalTitle').textContent = '新增站点';
    document.getElementById('editId').value = '';
    document.getElementById('formCertName').value = '';
    document.getElementById('formWebsite').value = '';
    document.getElementById('formType').value = 'web';
    document.getElementById('formApi').value = '';
    document.getElementById('formKey').value = '';
    document.getElementById('formStatus').value = '1';
    document.getElementById('websiteModal').classList.add('show');
}
function openEdit(id, certName, website, type, api, key, status) {
    document.getElementById('modalTitle').textContent = '编辑站点';
    document.getElementById('editId').value = id;
    document.getElementById('formCertName').value = certName;
    document.getElementById('formWebsite').value = website;
    document.getElementById('formType').value = type;
    document.getElementById('formApi').value = api;
    document.getElementById('formKey').value = key;
    document.getElementById('formStatus').value = status;
    document.getElementById('websiteModal').classList.add('show');
}
function submitForm() {
    var id = document.getElementById('editId').value;
    var cert_name = document.getElementById('formCertName').value;
    var website = document.getElementById('formWebsite').value;
    var type = document.getElementById('formType').value;
    var api = document.getElementById('formApi').value;
    var key = document.getElementById('formKey').value;
    var status = document.getElementById('formStatus').value;
    if (!cert_name) { alert('证书名称不能为空'); return; }

    var xhr = new XMLHttpRequest();
    var method = id ? 'POST' : 'PUT';
    xhr.open(method, '/admin/cert_website', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.setRequestHeader('admin-token', localStorage.getItem('admin_token'));
    xhr.onreadystatechange = function() {
        if (xhr.readyState == 4) {
            var res = JSON.parse(xhr.responseText);
            if (res.code == 0) {
                location.reload();
            } else {
                alert(res.echo);
            }
        }
    };
    var params = 'cert_name=' + encodeURIComponent(cert_name) + '&website=' + encodeURIComponent(website) + '&type=' + encodeURIComponent(type) + '&api=' + encodeURIComponent(api) + '&key=' + encodeURIComponent(key) + '&status=' + status;
    if (id) params += '&id=' + id;
    xhr.send(params);
}
function doDelete(id) {
    if (!confirm('确定要删除该站点吗？')) return;
    var xhr = new XMLHttpRequest();
    xhr.open('DELETE', '/admin/cert_website', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.setRequestHeader('admin-token', localStorage.getItem('admin_token'));
    xhr.onreadystatechange = function() {
        if (xhr.readyState == 4) {
            var res = JSON.parse(xhr.responseText);
            if (res.code == 0) {
                location.reload();
            } else {
                alert(res.echo);
            }
        }
    };
    xhr.send('id=' + id);
}
</script>
</body>
</html>
HTML;
        return response($html)->contentType('text/html');
    }

    private function create()
    {
        $cert_name = request()->put('cert_name');
        $website = request()->put('website', '');
        $type = request()->put('type', 'web');
        $api = request()->put('api', '');
        $key = request()->put('key', '');
        $status = intval(request()->put('status', '1')) ?: 1;

        if (empty($cert_name)) {
            Ret::Fail(400, null, '证书名称不能为空');
        }

        $model = AdminCertWebsiteModel::create([
            'cert_name' => $cert_name,
            'website' => $website,
            'type' => $type,
            'api' => $api,
            'key' => $key,
            'status' => $status,
        ]);

        Ret::Success(0, ['id' => $model['id']], '创建成功');
    }

    private function update()
    {
        $id = Input::PostInt('id');
        $model = new AdminCertWebsiteModel();
        $item = $model->findOrEmpty($id);

        if ($item->isEmpty()) {
            Ret::Fail(404, null, '记录不存在');
        }

        $data = [];
        if (request()->has('cert_name', 'post')) {
            $data['cert_name'] = Input::Post('cert_name');
        }
        if (request()->has('website', 'post')) {
            $data['website'] = Input::Post('website', false);
        }
        if (request()->has('type', 'post')) {
            $data['type'] = Input::Post('type', false);
        }
        if (request()->has('api', 'post')) {
            $data['api'] = Input::Post('api', false);
        }
        if (request()->has('key', 'post')) {
            $data['key'] = Input::Post('key', false);
        }
        if (request()->has('status', 'post')) {
            $data['status'] = Input::PostInt('status');
        }

        $item->save($data);
        Ret::Success(0, [], '更新成功');
    }

    private function delete()
    {
        $id = intval(request()->delete('id'));
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminCertWebsiteModel();
        $item = $model->findOrEmpty($id);

        if ($item->isEmpty()) {
            Ret::Fail(404, null, '记录不存在');
        }

        $item->delete();
        Ret::Success(0, [], '删除成功');
    }
}