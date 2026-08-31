<?php

namespace app\admin\controller;

use app\admin\model\AdminOssModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;
use Input;
use Ret;

class Oss extends CommonController
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
        $search = input('get.search', '');

        $model = new AdminOssModel();
        $query = $model->order('id', 'desc');
        if (!empty($search)) {
            $query->where('name', 'like', '%' . $search . '%');
        }
        $list = $query->paginate($limit, false, ['page' => $page])->toArray();

        $html = Layout::begin('存储项目', 'storage', 'oss');
        $html .= <<<HTML
    <div class="toolbar">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <h2>存储项目</h2>
            <div class="search-box">
                <input type="text" id="searchInput" placeholder="搜索项目名称" value="{$search}" style="padding: 8px 12px; border: 1px solid #d9d9d9; border-radius: 4px; width: 200px; outline: none;" onkeydown="if(event.key==='Enter')doSearch()">
                <button class="btn btn-primary" onclick="doSearch()" style="margin-left: 8px;">搜索</button>
            </div>
            <button class="btn btn-primary" onclick="openCreate()">新增项目</button>
        </div>
    </div>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>项目名称</th>
                <th>存储类型</th>
                <th>Token</th>
                <th>URL</th>
                <th>状态</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
HTML;
        foreach ($list['data'] as $item) {
            $typeOptions = '<option value="local"' . ($item['type'] === 'local' ? ' selected' : '') . '>local</option>';
            $typeOptions .= '<option value="oss"' . ($item['type'] === 'oss' ? ' selected' : '') . '>oss</option>';
            $typeOptions .= '<option value="all"' . ($item['type'] === 'all' ? ' selected' : '') . '>all</option>';
            $typeOptions .= '<option value="remote"' . ($item['type'] === 'remote' ? ' selected' : '') . '>remote</option>';
            $typeOptions .= '<option value="none"' . ($item['type'] === 'none' ? ' selected' : '') . '>none</option>';
            $statusBadge = $item['status'] == 1
                ? '<span class="status-badge active">启用</span>'
                : '<span class="status-badge inactive">禁用</span>';
            $tokenShort = mb_strlen($item['token']) > 16 ? mb_substr($item['token'], 0, 16) . '...' : $item['token'];
            $html .= <<<ROW
            <tr>
                <td>{$item['id']}</td>
                <td>{$item['name']}</td>
                <td><select class="type-select" data-id="{$item['id']}" onchange="changeType(this)">{$typeOptions}</select></td>
                <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{$item['token']}">{$tokenShort}</td>
                <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{$item['url']}">{$item['url']}</td>
                <td>{$statusBadge}</td>
                <td>
                    <button class="btn btn-sm btn-edit" onclick="openEdit({$item['id']}, '{$item['name']}', '{$item['type']}', '{$item['main_type']}', '{$item['code']}', '{$item['token']}', '{$item['oss_type']}', '{$item['oss_tag']}', '{$item['url']}', '{$item['ext']}', {$item['size']}, {$item['status']})">编辑</button>
                    <button class="btn btn-sm btn-del" onclick="doDelete({$item['id']})">删除</button>
                </td>
            </tr>
ROW;
        }
        $html .= <<<HTML
        </tbody>
    </table>
HTML;
        $html .= Layout::pagination((int)$list['current_page'], max(1, (int)ceil($list['total'] / $limit)), '/admin/oss', ['search' => $search], $limit);
        $html .= <<<HTML
<div class="modal" id="ossModal">
    <div class="modal-box">
        <h3 id="modalTitle">新增项目</h3>
        <input type="hidden" id="editId" value="">
        <div class="form-group">
            <label>项目名称</label>
            <input type="text" id="formName" placeholder="请输入项目名称">
        </div>
        <div class="form-group">
            <label>存储类型</label>
            <select id="formType">
                <option value="local">local</option>
                <option value="oss">oss</option>
                <option value="all">all</option>
                <option value="remote">remote</option>
                <option value="none">none</option>
            </select>
        </div>
        <div class="form-group">
            <label>主类型</label>
            <select id="formMainType">
                <option value="local">local</option>
                <option value="oss">oss</option>
            </select>
        </div>
        <div class="form-group">
            <label>项目密钥</label>
            <input type="text" id="formCode" placeholder="项目密钥">
        </div>
        <div class="form-group">
            <label>Token</label>
            <input type="text" id="formToken" placeholder="远程Token">
        </div>
        <div class="form-group">
            <label>OSS 类型</label>
            <select id="formOssType">
                <option value="none">none</option>
                <option value="aliyun">aliyun</option>
                <option value="tencent">tencent</option>
            </select>
        </div>
        <div class="form-group">
            <label>OSS 标签</label>
            <input type="text" id="formOssTag" placeholder="OSS标签" value="default">
        </div>
        <div class="form-group">
            <label>URL</label>
            <input type="text" id="formUrl" placeholder="CDN URL">
        </div>
        <div class="form-group">
            <label>允许扩展名</label>
            <input type="text" id="formExt" placeholder="jpg,png,gif,...">
        </div>
        <div class="form-group">
            <label>文件大小限制 (KB)</label>
            <input type="number" id="formSize" placeholder="16384">
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

<style>
.type-select { padding: 4px 6px; border: 1px solid #d9d9d9; border-radius: 4px; font-size: 13px; outline: none; cursor: pointer; background: #fff; }
.type-select:hover { border-color: #1677ff; }
.type-select:focus { border-color: #1677ff; box-shadow: 0 0 0 2px rgba(22,119,255,0.1); }
</style>
<script>
function doSearch() {
    var search = document.getElementById('searchInput').value;
    var url = '/admin/oss?page=1';
    if (search) url += '&search=' + encodeURIComponent(search);
    window.location.href = url;
}
function changeType(el) {
    var id = el.getAttribute('data-id');
    var type = el.value;
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '/admin/oss', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.setRequestHeader('admin-token', localStorage.getItem('admin_token'));
    xhr.onreadystatechange = function() {
        if (xhr.readyState == 4) {
            var res = JSON.parse(xhr.responseText);
            if (res.code != 0) {
                alert(res.echo);
                location.reload();
            }
        }
    };
    xhr.send('id=' + id + '&type=' + encodeURIComponent(type));
}
function closeModal() {
    document.getElementById('ossModal').classList.remove('show');
}
function openCreate() {
    document.getElementById('modalTitle').textContent = '新增项目';
    document.getElementById('editId').value = '';
    document.getElementById('formName').value = '';
    document.getElementById('formType').value = 'local';
    document.getElementById('formMainType').value = 'local';
    document.getElementById('formCode').value = '';
    document.getElementById('formToken').value = '';
    document.getElementById('formOssType').value = 'none';
    document.getElementById('formOssTag').value = 'default';
    document.getElementById('formUrl').value = '';
    document.getElementById('formExt').value = 'jpg,png,gif,bmp,jpeg';
    document.getElementById('formSize').value = '16384';
    document.getElementById('formStatus').value = '1';
    document.getElementById('ossModal').classList.add('show');
}
function openEdit(id, name, type, mainType, code, token, ossType, ossTag, url, ext, size, status) {
    document.getElementById('modalTitle').textContent = '编辑项目';
    document.getElementById('editId').value = id;
    document.getElementById('formName').value = name;
    document.getElementById('formType').value = type;
    document.getElementById('formMainType').value = mainType;
    document.getElementById('formCode').value = code;
    document.getElementById('formToken').value = token;
    document.getElementById('formOssType').value = ossType;
    document.getElementById('formOssTag').value = ossTag;
    document.getElementById('formUrl').value = url;
    document.getElementById('formExt').value = ext;
    document.getElementById('formSize').value = size;
    document.getElementById('formStatus').value = status;
    document.getElementById('ossModal').classList.add('show');
}
function submitForm() {
    var id = document.getElementById('editId').value;
    var name = document.getElementById('formName').value;
    var type = document.getElementById('formType').value;
    var mainType = document.getElementById('formMainType').value;
    var code = document.getElementById('formCode').value;
    var token = document.getElementById('formToken').value;
    var ossType = document.getElementById('formOssType').value;
    var ossTag = document.getElementById('formOssTag').value;
    var url = document.getElementById('formUrl').value;
    var ext = document.getElementById('formExt').value;
    var size = document.getElementById('formSize').value;
    var status = document.getElementById('formStatus').value;
    if (!name) { alert('项目名称不能为空'); return; }

    var xhr = new XMLHttpRequest();
    var method = id ? 'POST' : 'PUT';
    xhr.open(method, '/admin/oss', true);
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
    var params = 'name=' + encodeURIComponent(name) + '&type=' + encodeURIComponent(type) + '&main_type=' + encodeURIComponent(mainType) + '&code=' + encodeURIComponent(code) + '&token=' + encodeURIComponent(token) + '&oss_type=' + encodeURIComponent(ossType) + '&oss_tag=' + encodeURIComponent(ossTag) + '&url=' + encodeURIComponent(url) + '&ext=' + encodeURIComponent(ext) + '&size=' + size + '&status=' + status;
    if (id) params += '&id=' + id;
    xhr.send(params);
}
function doDelete(id) {
    if (!confirm('确定要删除该项目吗？')) return;
    var xhr = new XMLHttpRequest();
    xhr.open('DELETE', '/admin/oss', true);
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
        $name = Input::Put('name');
        if (empty($name)) {
            Ret::Fail(400, null, '项目名称不能为空');
        }

        $data = [
            'name' => $name,
            'type' => Input::Put('type', 'local'),
            'main_type' => Input::Put('main_type', 'local'),
            'code' => Input::Put('code', ''),
            'token' => Input::Put('token', ''),
            'oss_type' => Input::Put('oss_type', 'none'),
            'oss_tag' => Input::Put('oss_tag', 'default'),
            'url' => Input::Put('url', ''),
            'ext' => Input::Put('ext', 'jpg,png,gif,bmp,jpeg'),
            'size' => Input::PutInt('size', 16384),
            'status' => Input::PutInt('status', 1),
        ];

        AdminOssModel::create($data);
        Ret::Success(0, [], '创建成功');
    }

    private function update()
    {
        $id = Input::PostInt('id');
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminOssModel();
        $item = $model->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '项目不存在');
        }

        $data = [];
        if (request()->has('name', 'post')) $data['name'] = Input::Post('name');
        if (request()->has('type', 'post')) $data['type'] = Input::Post('type');
        if (request()->has('main_type', 'post')) $data['main_type'] = Input::Post('main_type');
        if (request()->has('code', 'post')) $data['code'] = Input::Post('code');
        if (request()->has('token', 'post')) $data['token'] = Input::Post('token');
        if (request()->has('oss_type', 'post')) $data['oss_type'] = Input::Post('oss_type');
        if (request()->has('oss_tag', 'post')) $data['oss_tag'] = Input::Post('oss_tag');
        if (request()->has('url', 'post')) $data['url'] = Input::Post('url');
        if (request()->has('ext', 'post')) $data['ext'] = Input::Post('ext');
        if (request()->has('size', 'post')) $data['size'] = Input::PostInt('size');
        if (request()->has('status', 'post')) $data['status'] = Input::PostInt('status');

        $item->save($data);
        Ret::Success(0, [], '更新成功');
    }

    private function delete()
    {
        $id = intval(request()->delete('id'));
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminOssModel();
        $item = $model->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '项目不存在');
        }

        $item->delete();
        Ret::Success(0, [], '删除成功');
    }
}