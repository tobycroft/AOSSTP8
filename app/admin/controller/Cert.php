<?php

namespace app\admin\controller;

use app\admin\model\AdminCertModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;
use Input;
use Ret;

class Cert extends CommonController
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

        $model = new AdminCertModel();
        $list = $model->order('id', 'desc')->paginate($limit, false, ['page' => $page]);

        $currentPage = (int)$list->currentPage();
        $total = $list->total();
        $totalPages = max(1, (int)ceil($total / $limit));

        $html = Layout::begin('证书项目', 'cert', 'cert');
        $html .= <<<HTML
    <div class="toolbar">
        <h2>证书项目</h2>
        <button class="btn btn-primary" onclick="openCreate()">新增项目</button>
    </div>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>AppName</th>
                <th>AppKey</th>
                <th>BT API</th>
                <th>BT Key</th>
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
                <td>{$item['appname']}</td>
                <td>{$item['appkey']}</td>
                <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{$item['bt_api']}</td>
                <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{$item['bt_key']}</td>
                <td>{$statusBadge}</td>
                <td>
                    <button class="btn btn-sm btn-edit" onclick="openEdit({$item['id']}, '{$item['appname']}', '{$item['appkey']}', '{$item['bt_api']}', '{$item['bt_key']}', {$item['status']})">编辑</button>
                    <button class="btn btn-sm btn-del" onclick="doDelete({$item['id']})">删除</button>
                </td>
            </tr>
ROW;
        }
        $html .= <<<HTML
        </tbody>
    </table>
HTML;
        $html .= Layout::pagination($currentPage, $totalPages, '/admin/cert');
        $html .= <<<HTML
<div class="modal" id="certModal">
    <div class="modal-box">
        <h3 id="modalTitle">新增项目</h3>
        <input type="hidden" id="editId" value="">
        <div class="form-group">
            <label>AppName</label>
            <input type="text" id="formAppname" placeholder="请输入 AppName">
        </div>
        <div class="form-group">
            <label>AppKey</label>
            <input type="text" id="formAppkey" placeholder="请输入 AppKey">
        </div>
        <div class="form-group">
            <label>BT API</label>
            <input type="text" id="formBtApi" placeholder="请输入 BT API 地址">
        </div>
        <div class="form-group">
            <label>BT Key</label>
            <input type="text" id="formBtKey" placeholder="请输入 BT Key">
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
function closeModal() {
    document.getElementById('certModal').classList.remove('show');
}
function openCreate() {
    document.getElementById('modalTitle').textContent = '新增项目';
    document.getElementById('editId').value = '';
    document.getElementById('formAppname').value = '';
    document.getElementById('formAppkey').value = '';
    document.getElementById('formBtApi').value = '';
    document.getElementById('formBtKey').value = '';
    document.getElementById('formStatus').value = '1';
    document.getElementById('certModal').classList.add('show');
}
function openEdit(id, appname, appkey, btApi, btKey, status) {
    document.getElementById('modalTitle').textContent = '编辑项目';
    document.getElementById('editId').value = id;
    document.getElementById('formAppname').value = appname;
    document.getElementById('formAppkey').value = appkey;
    document.getElementById('formBtApi').value = btApi;
    document.getElementById('formBtKey').value = btKey;
    document.getElementById('formStatus').value = status;
    document.getElementById('certModal').classList.add('show');
}
function submitForm() {
    var id = document.getElementById('editId').value;
    var appname = document.getElementById('formAppname').value;
    var appkey = document.getElementById('formAppkey').value;
    var bt_api = document.getElementById('formBtApi').value;
    var bt_key = document.getElementById('formBtKey').value;
    var status = document.getElementById('formStatus').value;
    if (!appname) { alert('AppName 不能为空'); return; }

    var xhr = new XMLHttpRequest();
    var method = id ? 'POST' : 'PUT';
    xhr.open(method, '/admin/cert', true);
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
    var params = 'appname=' + encodeURIComponent(appname) + '&appkey=' + encodeURIComponent(appkey) + '&bt_api=' + encodeURIComponent(bt_api) + '&bt_key=' + encodeURIComponent(bt_key) + '&status=' + status;
    if (id) params += '&id=' + id;
    xhr.send(params);
}
function doDelete(id) {
    if (!confirm('确定要删除该项目吗？')) return;
    var xhr = new XMLHttpRequest();
    xhr.open('DELETE', '/admin/cert', true);
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
        $appname = request()->put('appname');
        $appkey = request()->put('appkey', '');
        $bt_api = request()->put('bt_api', '');
        $bt_key = request()->put('bt_key', '');
        $status = intval(request()->put('status', '1')) ?: 1;

        if (empty($appname)) {
            Ret::Fail(400, null, 'AppName 不能为空');
        }

        $cert = AdminCertModel::create([
            'appname' => $appname,
            'appkey' => $appkey,
            'bt_api' => $bt_api,
            'bt_key' => $bt_key,
            'status' => $status,
        ]);

        Ret::Success(0, ['id' => $cert['id']], '创建成功');
    }

    private function update()
    {
        $id = Input::PostInt('id');
        $model = new AdminCertModel();
        $cert = $model->findOrEmpty($id);

        if ($cert->isEmpty()) {
            Ret::Fail(404, null, '项目不存在');
        }

        $data = [];
        if (request()->has('appname', 'post')) {
            $data['appname'] = Input::Post('appname');
        }
        if (request()->has('appkey', 'post')) {
            $data['appkey'] = Input::Post('appkey', false);
        }
        if (request()->has('bt_api', 'post')) {
            $data['bt_api'] = Input::Post('bt_api', false);
        }
        if (request()->has('bt_key', 'post')) {
            $data['bt_key'] = Input::Post('bt_key', false);
        }
        if (request()->has('status', 'post')) {
            $data['status'] = Input::PostInt('status');
        }

        $cert->save($data);
        Ret::Success(0, [], '更新成功');
    }

    private function delete()
    {
        $id = intval(request()->delete('id'));
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminCertModel();
        $cert = $model->findOrEmpty($id);

        if ($cert->isEmpty()) {
            Ret::Fail(404, null, '项目不存在');
        }

        $cert->delete();
        Ret::Success(0, [], '删除成功');
    }
}