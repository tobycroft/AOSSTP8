<?php

namespace app\admin\controller;

use app\admin\model\AdminRoleModel;
use app\admin\model\AdminRoleUserModel;
use app\admin\utils\AdminAuth;
use BaseController\CommonController;
use Input;
use Ret;

class Role extends CommonController
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

        $model = new AdminRoleModel();
        $list = $model->api_list($page, $limit);

        $items = [];
        foreach ($list as $item) {
            $items[] = [
                'id' => $item['id'],
                'name' => $item['name'],
                'description' => $item['description'],
                'status' => $item['status'],
                'date' => $item['date'],
            ];
        }

        $currentPage = (int)$list->currentPage();
        $total = $list->total();
        $totalPages = max(1, (int)ceil($total / $limit));

        $html = <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AOSS 角色管理</title>
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
.modal-box { background: #fff; border-radius: 8px; padding: 24px; width: 480px; max-width: 90%; }
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
    <a class="menu-item" href="/admin/user">用户管理</a>
    <a class="menu-item active" href="/admin/role">角色管理</a>
    <a class="menu-item" href="/admin/menu">菜单管理</a>
</div>
<div class="main">
    <div class="toolbar">
        <h2>角色管理</h2>
        <button class="btn btn-primary" onclick="openCreate()">新增角色</button>
    </div>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>名称</th>
                <th>描述</th>
                <th>状态</th>
                <th>创建时间</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
HTML;
        foreach ($items as $item) {
            $statusBadge = $item['status'] == 1
                ? '<span class="status-badge active">启用</span>'
                : '<span class="status-badge inactive">禁用</span>';
            $html .= <<<ROW
            <tr>
                <td>{$item['id']}</td>
                <td>{$item['name']}</td>
                <td>{$item['description']}</td>
                <td>{$statusBadge}</td>
                <td>{$item['date']}</td>
                <td>
                    <button class="btn btn-sm btn-edit" onclick="openEdit({$item['id']}, '{$item['name']}', '{$item['description']}', {$item['status']})">编辑</button>
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
            $html .= "<a href=\"/admin/role?page={$i}\"{$active}>{$i}</a>";
        }
        $html .= <<<HTML
    </div>
</div>

<div class="modal" id="roleModal">
    <div class="modal-box">
        <h3 id="modalTitle">新增角色</h3>
        <input type="hidden" id="editId" value="">
        <div class="form-group">
            <label>名称</label>
            <input type="text" id="formName" placeholder="请输入角色名称">
        </div>
        <div class="form-group">
            <label>描述</label>
            <input type="text" id="formDesc" placeholder="请输入角色描述">
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
            <button class="btn btn-primary" id="modalSubmit" onclick="submitForm()">确定</button>
        </div>
    </div>
</div>

<script>
function closeModal() {
    document.getElementById('roleModal').classList.remove('show');
}
function openCreate() {
    document.getElementById('modalTitle').textContent = '新增角色';
    document.getElementById('editId').value = '';
    document.getElementById('formName').value = '';
    document.getElementById('formDesc').value = '';
    document.getElementById('formStatus').value = '1';
    document.getElementById('roleModal').classList.add('show');
}
function openEdit(id, name, desc, status) {
    document.getElementById('modalTitle').textContent = '编辑角色';
    document.getElementById('editId').value = id;
    document.getElementById('formName').value = name;
    document.getElementById('formDesc').value = desc;
    document.getElementById('formStatus').value = status;
    document.getElementById('roleModal').classList.add('show');
}
function submitForm() {
    var id = document.getElementById('editId').value;
    var name = document.getElementById('formName').value;
    var desc = document.getElementById('formDesc').value;
    var status = document.getElementById('formStatus').value;
    if (!name) { alert('名称不能为空'); return; }

    var xhr = new XMLHttpRequest();
    var method = id ? 'POST' : 'PUT';
    xhr.open(method, '/admin/role', true);
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
    var params = 'name=' + encodeURIComponent(name) + '&description=' + encodeURIComponent(desc) + '&status=' + status;
    if (id) params += '&id=' + id;
    xhr.send(params);
}
function doDelete(id) {
    if (!confirm('确定要删除该角色吗？')) return;
    var xhr = new XMLHttpRequest();
    xhr.open('DELETE', '/admin/role', true);
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
        $name = request()->put('name');
        $description = request()->put('description', '');

        if (empty($name)) {
            Ret::Fail(400, null, '名称不能为空');
        }

        $role = AdminRoleModel::create([
            'name' => $name,
            'description' => $description,
        ]);

        Ret::Success(0, ['id' => $role['id']], '创建成功');
    }

    private function update()
    {
        $id = Input::PostInt('id');
        $model = new AdminRoleModel();
        $role = $model->findOrEmpty($id);

        if ($role->isEmpty()) {
            Ret::Fail(404, null, '角色不存在');
        }

        $data = [];
        if (request()->has('name', 'post')) {
            $data['name'] = Input::Post('name');
        }
        if (request()->has('description', 'post')) {
            $data['description'] = Input::Post('description', false);
        }
        if (request()->has('status', 'post')) {
            $data['status'] = Input::PostInt('status');
        }

        $role->save($data);
        Ret::Success(0, [], '更新成功');
    }

    private function delete()
    {
        $id = intval(request()->delete('id'));
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminRoleModel();
        $role = $model->findOrEmpty($id);

        if ($role->isEmpty()) {
            Ret::Fail(404, null, '角色不存在');
        }

        $role->delete();
        AdminRoleUserModel::where('role_id', '=', $id)->delete();

        Ret::Success(0, [], '删除成功');
    }

    public function all()
    {
        $model = new AdminRoleModel();
        $list = $model->where('status', '=', 1)->select();

        $items = [];
        foreach ($list as $item) {
            $items[] = [
                'id' => $item['id'],
                'name' => $item['name'],
            ];
        }

        Ret::Success(0, $items);
    }

    public function bind()
    {
        $user_id = Input::PostInt('user_id');
        $role_ids = Input::PostJson('role_ids');

        (new AdminRoleUserModel())->api_delete_by_user($user_id);

        foreach ($role_ids as $role_id) {
            AdminRoleUserModel::create([
                'user_id' => $user_id,
                'role_id' => $role_id,
            ]);
        }

        Ret::Success(0, [], '绑定成功');
    }

    public function getUserRoles()
    {
        $user_id = Input::PostInt('user_id');
        $list = (new AdminRoleUserModel())->api_find_by_user($user_id);

        $role_ids = [];
        foreach ($list as $item) {
            $role_ids[] = $item['role_id'];
        }

        Ret::Success(0, $role_ids);
    }
}