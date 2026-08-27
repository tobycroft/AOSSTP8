<?php

namespace app\admin\controller;

use app\admin\model\AdminCertWebsiteModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
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

        $html = Layout::begin('证书站点', 'cert', 'cert_website');
        $html .= <<<HTML
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
HTML;
        $html .= Layout::pagination($currentPage, $totalPages, '/admin/cert_website');
        $html .= <<<HTML
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
HTML;
        $html .= Layout::end();
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