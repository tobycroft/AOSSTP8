<?php

namespace app\admin\controller;

use app\admin\model\AdminUserModel;
use app\admin\model\AdminRoleUserModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;
use Input;
use Ret;

class User extends CommonController
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

        $model = new AdminUserModel();
        $list = $model->api_list($page, $limit);

        $items = [];
        foreach ($list as $item) {
            $items[] = [
                'id' => $item['id'],
                'username' => $item['username'],
                'nickname' => $item['nickname'],
                'email' => $item['email'],
                'phone' => $item['phone'],
                'status' => $item['status'],
                'is_super' => $item['is_super'],
                'login_ip' => $item['login_ip'],
                'login_time' => $item['login_time'],
                'date' => $item['date'],
            ];
        }

        $currentPage = (int)$list->currentPage();
        $total = $list->total();
        $totalPages = max(1, (int)ceil($total / $limit));

        $html = Layout::begin('用户管理', 'admin', 'user');
        $html .= <<<HTML
    <div class="toolbar">
        <h2>用户管理</h2>
        <button class="btn btn-primary" onclick="openCreate()">新增用户</button>
    </div>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>用户名</th>
                <th>昵称</th>
                <th>邮箱</th>
                <th>手机</th>
                <th>状态</th>
                <th>超级管理员</th>
                <th>最后登录</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
HTML;
        foreach ($items as $item) {
            $statusBadge = $item['status'] == 1
                ? '<span class="status-badge active">启用</span>'
                : '<span class="status-badge inactive">禁用</span>';
            $superText = $item['is_super'] ? '是' : '否';
            $html .= <<<ROW
            <tr>
                <td>{$item['id']}</td>
                <td>{$item['username']}</td>
                <td>{$item['nickname']}</td>
                <td>{$item['email']}</td>
                <td>{$item['phone']}</td>
                <td>{$statusBadge}</td>
                <td>{$superText}</td>
                <td>{$item['login_time']}</td>
                <td>
                    <button class="btn btn-sm btn-edit" onclick="openEdit({$item['id']}, '{$item['username']}', '{$item['nickname']}', '{$item['email']}', '{$item['phone']}', {$item['status']})">编辑</button>
                    <button class="btn btn-sm btn-del" onclick="doDelete({$item['id']})">删除</button>
                </td>
            </tr>
ROW;
        }
        $html .= <<<HTML
        </tbody>
    </table>
HTML;
        $html .= Layout::pagination($currentPage, $totalPages, '/admin/user');
        $html .= <<<HTML
<div class="modal" id="userModal">
    <div class="modal-box">
        <h3 id="modalTitle">新增用户</h3>
        <input type="hidden" id="editId" value="">
        <div class="form-group">
            <label>用户名</label>
            <input type="text" id="formUsername" placeholder="请输入用户名">
        </div>
        <div class="form-group">
            <label>密码</label>
            <input type="password" id="formPassword" placeholder="留空则不修改">
        </div>
        <div class="form-group">
            <label>昵称</label>
            <input type="text" id="formNickname" placeholder="请输入昵称">
        </div>
        <div class="form-group">
            <label>邮箱</label>
            <input type="text" id="formEmail" placeholder="请输入邮箱">
        </div>
        <div class="form-group">
            <label>手机</label>
            <input type="text" id="formPhone" placeholder="请输入手机">
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
    document.getElementById('userModal').classList.remove('show');
}
function openCreate() {
    document.getElementById('modalTitle').textContent = '新增用户';
    document.getElementById('editId').value = '';
    document.getElementById('formUsername').value = '';
    document.getElementById('formPassword').value = '';
    document.getElementById('formNickname').value = '';
    document.getElementById('formEmail').value = '';
    document.getElementById('formPhone').value = '';
    document.getElementById('formStatus').value = '1';
    document.getElementById('userModal').classList.add('show');
}
function openEdit(id, username, nickname, email, phone, status) {
    document.getElementById('modalTitle').textContent = '编辑用户';
    document.getElementById('editId').value = id;
    document.getElementById('formUsername').value = username;
    document.getElementById('formPassword').value = '';
    document.getElementById('formNickname').value = nickname;
    document.getElementById('formEmail').value = email;
    document.getElementById('formPhone').value = phone;
    document.getElementById('formStatus').value = status;
    document.getElementById('userModal').classList.add('show');
}
function submitForm() {
    var id = document.getElementById('editId').value;
    var username = document.getElementById('formUsername').value;
    var password = document.getElementById('formPassword').value;
    var nickname = document.getElementById('formNickname').value;
    var email = document.getElementById('formEmail').value;
    var phone = document.getElementById('formPhone').value;
    var status = document.getElementById('formStatus').value;
    if (!username) { alert('用户名不能为空'); return; }

    var xhr = new XMLHttpRequest();
    var method = id ? 'POST' : 'PUT';
    xhr.open(method, '/admin/user', true);
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
    var params = 'username=' + encodeURIComponent(username) + '&nickname=' + encodeURIComponent(nickname) + '&email=' + encodeURIComponent(email) + '&phone=' + encodeURIComponent(phone) + '&status=' + status;
    if (password) params += '&password=' + encodeURIComponent(password);
    if (id) params += '&id=' + id;
    xhr.send(params);
}
function doDelete(id) {
    if (!confirm('确定要删除该用户吗？')) return;
    var xhr = new XMLHttpRequest();
    xhr.open('DELETE', '/admin/user', true);
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
HTML;
        $html .= Layout::end();
        return response($html)->contentType('text/html');
    }

    private function create()
    {
        $username = request()->put('username');
        $password = request()->put('password');
        $nickname = request()->put('nickname', '') ?: $username;
        $email = request()->put('email', '');
        $phone = request()->put('phone', '');
        $status = intval(request()->put('status', '1')) ?: 1;

        if (empty($username)) {
            Ret::Fail(400, null, '用户名不能为空');
        }
        if (empty($password)) {
            Ret::Fail(400, null, '密码不能为空');
        }

        $model = new AdminUserModel();
        $exist = $model->api_find_username($username);
        if (!$exist->isEmpty()) {
            Ret::Fail(406, null, '用户名已存在');
        }

        $user = AdminUserModel::create([
            'username' => $username,
            'password' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]),
            'nickname' => $nickname,
            'email' => $email,
            'phone' => $phone,
            'status' => $status,
        ]);

        Ret::Success(0, ['id' => $user['id']], '创建成功');
    }

    private function update()
    {
        $id = Input::PostInt('id');
        $model = new AdminUserModel();
        $user = $model->api_find_id($id);

        if ($user->isEmpty()) {
            Ret::Fail(404, null, '用户不存在');
        }

        $data = [];
        if (request()->has('nickname', 'post')) {
            $data['nickname'] = Input::Post('nickname');
        }
        if (request()->has('email', 'post')) {
            $data['email'] = Input::Post('email', false);
        }
        if (request()->has('phone', 'post')) {
            $data['phone'] = Input::Post('phone', false);
        }
        if (request()->has('status', 'post')) {
            $data['status'] = Input::PostInt('status');
        }
        if (request()->has('password', 'post')) {
            $data['password'] = password_hash(Input::Post('password'), PASSWORD_BCRYPT, ['cost' => 10]);
        }

        $user->save($data);
        Ret::Success(0, [], '更新成功');
    }

    private function delete()
    {
        $id = intval(request()->delete('id'));
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $current = AdminAuth::getLoginUser();
        if ($current['id'] == $id) {
            Ret::Fail(403, null, '不能删除自己');
        }

        $model = new AdminUserModel();
        $user = $model->api_find_id($id);

        if ($user->isEmpty()) {
            Ret::Fail(404, null, '用户不存在');
        }

        $user->delete();
        (new AdminRoleUserModel())->api_delete_by_user($id);

        Ret::Success(0, [], '删除成功');
    }
}