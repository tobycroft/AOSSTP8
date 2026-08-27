<?php

namespace app\admin\controller;

use app\admin\model\AdminMenuModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;
use Input;
use Ret;

class Menu extends CommonController
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
        $model = new AdminMenuModel();
        $list = $model->order('sort', 'asc')->order('id', 'asc')->select();

        $items = [];
        foreach ($list as $item) {
            $items[] = [
                'id' => $item['id'],
                'parent_id' => $item['parent_id'],
                'name' => $item['name'],
                'icon' => $item['icon'],
                'path' => $item['path'],
                'sort' => $item['sort'],
                'status' => $item['status'],
            ];
        }

        $html = Layout::begin('菜单管理', 'admin', 'menu');
        $html .= <<<HTML
    <div class="toolbar">
        <h2>菜单管理</h2>
        <button class="btn btn-primary" onclick="openCreate()">新增菜单</button>
    </div>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>父级ID</th>
                <th>名称</th>
                <th>图标</th>
                <th>路径</th>
                <th>排序</th>
                <th>状态</th>
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
                <td>{$item['parent_id']}</td>
                <td>{$item['name']}</td>
                <td>{$item['icon']}</td>
                <td>{$item['path']}</td>
                <td>{$item['sort']}</td>
                <td>{$statusBadge}</td>
                <td>
                    <button class="btn btn-sm btn-edit" onclick="openEdit({$item['id']}, {$item['parent_id']}, '{$item['name']}', '{$item['icon']}', '{$item['path']}', {$item['sort']}, {$item['status']})">编辑</button>
                    <button class="btn btn-sm btn-del" onclick="doDelete({$item['id']})">删除</button>
                </td>
            </tr>
ROW;
        }
        $html .= <<<HTML
        </tbody>
    </table>

<div class="modal" id="menuModal">
    <div class="modal-box">
        <h3 id="modalTitle">新增菜单</h3>
        <input type="hidden" id="editId" value="">
        <div class="form-group">
            <label>父级ID</label>
            <input type="number" id="formParentId" placeholder="0 为顶级菜单" value="0">
        </div>
        <div class="form-group">
            <label>名称</label>
            <input type="text" id="formName" placeholder="请输入菜单名称">
        </div>
        <div class="form-group">
            <label>图标</label>
            <input type="text" id="formIcon" placeholder="请输入图标 class">
        </div>
        <div class="form-group">
            <label>路径</label>
            <input type="text" id="formPath" placeholder="请输入路由路径">
        </div>
        <div class="form-group">
            <label>排序</label>
            <input type="number" id="formSort" placeholder="数字越小越靠前" value="0">
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
    document.getElementById('menuModal').classList.remove('show');
}
function openCreate() {
    document.getElementById('modalTitle').textContent = '新增菜单';
    document.getElementById('editId').value = '';
    document.getElementById('formParentId').value = '0';
    document.getElementById('formName').value = '';
    document.getElementById('formIcon').value = '';
    document.getElementById('formPath').value = '';
    document.getElementById('formSort').value = '0';
    document.getElementById('formStatus').value = '1';
    document.getElementById('menuModal').classList.add('show');
}
function openEdit(id, parentId, name, icon, path, sort, status) {
    document.getElementById('modalTitle').textContent = '编辑菜单';
    document.getElementById('editId').value = id;
    document.getElementById('formParentId').value = parentId;
    document.getElementById('formName').value = name;
    document.getElementById('formIcon').value = icon;
    document.getElementById('formPath').value = path;
    document.getElementById('formSort').value = sort;
    document.getElementById('formStatus').value = status;
    document.getElementById('menuModal').classList.add('show');
}
function submitForm() {
    var id = document.getElementById('editId').value;
    var parent_id = document.getElementById('formParentId').value;
    var name = document.getElementById('formName').value;
    var icon = document.getElementById('formIcon').value;
    var path = document.getElementById('formPath').value;
    var sort = document.getElementById('formSort').value;
    var status = document.getElementById('formStatus').value;
    if (!name) { alert('名称不能为空'); return; }

    var xhr = new XMLHttpRequest();
    var method = id ? 'POST' : 'PUT';
    xhr.open(method, '/admin/menu', true);
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
    var params = 'name=' + encodeURIComponent(name) + '&parent_id=' + parent_id + '&icon=' + encodeURIComponent(icon) + '&path=' + encodeURIComponent(path) + '&sort=' + sort + '&status=' + status;
    if (id) params += '&id=' + id;
    xhr.send(params);
}
function doDelete(id) {
    if (!confirm('确定要删除该菜单吗？')) return;
    var xhr = new XMLHttpRequest();
    xhr.open('DELETE', '/admin/menu', true);
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
        $parent_id = intval(request()->put('parent_id', '0')) ?: 0;
        $name = request()->put('name');
        $icon = request()->put('icon', '');
        $path = request()->put('path', '');
        $sort = intval(request()->put('sort', '0')) ?: 0;
        $status = intval(request()->put('status', '1')) ?: 1;

        if (empty($name)) {
            Ret::Fail(400, null, '名称不能为空');
        }

        $menu = AdminMenuModel::create([
            'parent_id' => $parent_id,
            'name' => $name,
            'icon' => $icon,
            'path' => $path,
            'sort' => $sort,
            'status' => $status,
        ]);

        Ret::Success(0, ['id' => $menu['id']], '创建成功');
    }

    private function update()
    {
        $id = Input::PostInt('id');
        $model = new AdminMenuModel();
        $menu = $model->findOrEmpty($id);

        if ($menu->isEmpty()) {
            Ret::Fail(404, null, '菜单不存在');
        }

        $data = [];
        if (request()->has('parent_id', 'post')) {
            $data['parent_id'] = Input::PostInt('parent_id');
        }
        if (request()->has('name', 'post')) {
            $data['name'] = Input::Post('name');
        }
        if (request()->has('icon', 'post')) {
            $data['icon'] = Input::Post('icon', false);
        }
        if (request()->has('path', 'post')) {
            $data['path'] = Input::Post('path', false);
        }
        if (request()->has('sort', 'post')) {
            $data['sort'] = Input::PostInt('sort');
        }
        if (request()->has('status', 'post')) {
            $data['status'] = Input::PostInt('status');
        }

        $menu->save($data);
        Ret::Success(0, [], '更新成功');
    }

    private function delete()
    {
        $id = intval(request()->delete('id'));
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminMenuModel();
        $menu = $model->findOrEmpty($id);

        if ($menu->isEmpty()) {
            Ret::Fail(404, null, '菜单不存在');
        }

        $model->where('parent_id', '=', $id)->delete();
        $menu->delete();

        Ret::Success(0, [], '删除成功');
    }
}