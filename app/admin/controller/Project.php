<?php

namespace app\admin\controller;

use app\admin\model\AdminProjectModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;
use Input;
use Ret;

class Project extends CommonController
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

        $model = new AdminProjectModel();
        $query = $model->order('appid', 'desc');
        if (!empty($search)) {
            $query->where('project', 'like', '%' . $search . '%');
        }
        $list = $query->paginate($limit, false, ['page' => $page])->toArray();

        $html = Layout::begin('项目管理', 'project', 'project');
        $html .= <<<HTML
    <div class="toolbar">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <h2>项目管理</h2>
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
                <th>APPID</th>
                <th>项目名称</th>
                <th>AppSecret</th>
                <th>开放 Token</th>
                <th>开放令牌</th>
                <th>OSS项目</th>
                <th>可用</th>
                <th>创建时间</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
HTML;
        foreach ($list['data'] as $item) {
            $availBadge = $item['is_avail'] == 1
                ? '<span class="status-badge active">是</span>'
                : '<span class="status-badge inactive">否</span>';
            $tokenBadge = $item['is_opentoken'] == 1
                ? '<span class="status-badge active">启用</span>'
                : '<span class="status-badge inactive">关闭</span>';
            $appsecretShort = mb_strlen($item['appsecret'] ?? '') > 16 ? mb_substr($item['appsecret'], 0, 16) . '...' : ($item['appsecret'] ?: '-');
            $openTokenShort = mb_strlen($item['open_token'] ?? '') > 16 ? mb_substr($item['open_token'], 0, 16) . '...' : ($item['open_token'] ?: '-');
            $html .= <<<ROW
            <tr>
                <td>{$item['appid']}</td>
                <td>{$item['project']}</td>
                <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{$item['appsecret']}">{$appsecretShort}</td>
                <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{$item['open_token']}">{$openTokenShort}</td>
                <td>{$tokenBadge}</td>
                <td>{$item['oss_project']}</td>
                <td>{$availBadge}</td>
                <td>{$item['date']}</td>
                <td>
                    <button class="btn btn-sm btn-edit" onclick="openEdit({$item['appid']}, '{$item['project']}', '{$item['appsecret']}', '{$item['open_token']}', {$item['is_opentoken']}, '{$item['oss_project']}', {$item['is_avail']})">编辑</button>
                    <button class="btn btn-sm btn-del" onclick="doDelete({$item['appid']})">删除</button>
                </td>
            </tr>
ROW;
        }
        $html .= <<<HTML
        </tbody>
    </table>
HTML;
        $html .= Layout::pagination((int)$list['current_page'], max(1, (int)ceil($list['total'] / $limit)), '/admin/project', ['search' => $search], $limit);
        $html .= <<<HTML
<div class="modal" id="projectModal">
    <div class="modal-box">
        <h3 id="modalTitle">新增项目</h3>
        <input type="hidden" id="editId" value="">
        <div class="form-group">
            <label>项目名称</label>
            <input type="text" id="formProject" placeholder="请输入项目名称">
        </div>
        <div class="form-group">
            <label>AppSecret</label>
            <input type="text" id="formAppsecret" placeholder="项目密钥">
        </div>
        <div class="form-group">
            <label>开放 Token</label>
            <input type="text" id="formOpenToken" placeholder="开放令牌">
        </div>
        <div class="form-group">
            <label>开放令牌</label>
            <select id="formIsOpentoken">
                <option value="1">启用</option>
                <option value="0">关闭</option>
            </select>
        </div>
        <div class="form-group">
            <label>OSS 项目</label>
            <input type="text" id="formOssProject" placeholder="关联的 OSS 项目名">
        </div>
        <div class="form-group">
            <label>可用</label>
            <select id="formIsAvail">
                <option value="1">是</option>
                <option value="0">否</option>
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
    var url = '/admin/project?page=1';
    if (search) url += '&search=' + encodeURIComponent(search);
    window.location.href = url;
}
function closeModal() {
    document.getElementById('projectModal').classList.remove('show');
}
function openCreate() {
    document.getElementById('modalTitle').textContent = '新增项目';
    document.getElementById('editId').value = '';
    document.getElementById('formProject').value = '';
    document.getElementById('formAppsecret').value = '';
    document.getElementById('formOpenToken').value = '';
    document.getElementById('formIsOpentoken').value = '1';
    document.getElementById('formOssProject').value = '';
    document.getElementById('formIsAvail').value = '1';
    document.getElementById('projectModal').classList.add('show');
}
function openEdit(appid, project, appsecret, openToken, isOpentoken, ossProject, isAvail) {
    document.getElementById('modalTitle').textContent = '编辑项目';
    document.getElementById('editId').value = appid;
    document.getElementById('formProject').value = project;
    document.getElementById('formAppsecret').value = appsecret;
    document.getElementById('formOpenToken').value = openToken;
    document.getElementById('formIsOpentoken').value = isOpentoken;
    document.getElementById('formOssProject').value = ossProject;
    document.getElementById('formIsAvail').value = isAvail;
    document.getElementById('projectModal').classList.add('show');
}
function submitForm() {
    var id = document.getElementById('editId').value;
    var project = document.getElementById('formProject').value;
    var appsecret = document.getElementById('formAppsecret').value;
    var openToken = document.getElementById('formOpenToken').value;
    var isOpentoken = document.getElementById('formIsOpentoken').value;
    var ossProject = document.getElementById('formOssProject').value;
    var isAvail = document.getElementById('formIsAvail').value;
    if (!project) { alert('项目名称不能为空'); return; }

    var xhr = new XMLHttpRequest();
    var method = id ? 'POST' : 'PUT';
    xhr.open(method, '/admin/project', true);
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
    var params = 'project=' + encodeURIComponent(project) + '&appsecret=' + encodeURIComponent(appsecret) + '&open_token=' + encodeURIComponent(openToken) + '&is_opentoken=' + isOpentoken + '&oss_project=' + encodeURIComponent(ossProject) + '&is_avail=' + isAvail;
    if (id) params += '&appid=' + id;
    xhr.send(params);
}
function doDelete(appid) {
    if (!confirm('确定要删除该项目吗？')) return;
    var xhr = new XMLHttpRequest();
    xhr.open('DELETE', '/admin/project', true);
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
    xhr.send('appid=' + appid);
}
</script>
HTML;
        $html .= Layout::end();
        return response($html)->contentType('text/html');
    }

    private function create()
    {
        $project = Input::Put('project');
        if (empty($project)) {
            Ret::Fail(400, null, '项目名称不能为空');
        }

        $data = [
            'project' => $project,
            'appsecret' => Input::Put('appsecret', ''),
            'open_token' => Input::Put('open_token', ''),
            'is_opentoken' => Input::PutInt('is_opentoken', 1),
            'oss_project' => Input::Put('oss_project', ''),
            'is_avail' => Input::PutInt('is_avail', 1),
        ];

        AdminProjectModel::create($data);
        Ret::Success(0, [], '创建成功');
    }

    private function update()
    {
        $appid = Input::PostInt('appid');
        if (!$appid) {
            Ret::Fail(400, null, '缺少参数[appid]');
        }

        $model = new AdminProjectModel();
        $item = $model->findOrEmpty($appid);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '项目不存在');
        }

        $data = [];
        if (request()->has('project', 'post')) $data['project'] = Input::Post('project');
        if (request()->has('appsecret', 'post')) $data['appsecret'] = Input::Post('appsecret');
        if (request()->has('open_token', 'post')) $data['open_token'] = Input::Post('open_token');
        if (request()->has('is_opentoken', 'post')) $data['is_opentoken'] = Input::PostInt('is_opentoken');
        if (request()->has('oss_project', 'post')) $data['oss_project'] = Input::Post('oss_project');
        if (request()->has('is_avail', 'post')) $data['is_avail'] = Input::PostInt('is_avail');

        $item->save($data);
        Ret::Success(0, [], '更新成功');
    }

    private function delete()
    {
        $appid = intval(request()->delete('appid'));
        if (!$appid) {
            Ret::Fail(400, null, '缺少参数[appid]');
        }

        $model = new AdminProjectModel();
        $item = $model->findOrEmpty($appid);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '项目不存在');
        }

        $item->delete();
        Ret::Success(0, [], '删除成功');
    }
}