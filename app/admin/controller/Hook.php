<?php

namespace app\admin\controller;

use app\admin\model\AdminHookModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;
use Input;
use Ret;

class Hook extends CommonController
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
                if (request()->post('batch_delete')) {
                    return $this->batchDelete();
                }
                return $this->update();
            case 'DELETE':
                return $this->delete();
        }
        Ret::Fail(405, null, '不支持的请求方法');
    }

    private function page()
    {
        $page = input('get.page', 1, 'intval');
        $limit = input('get.limit', 15, 'intval');
        $tag = input('get.tag', '', 'trim');

        $model = new AdminHookModel();
        $query = $model->where('id', '>', 0);
        if (!empty($tag)) {
            $query->where('tag', 'like', "%{$tag}%");
        }
        $query->order('id', 'desc');
        $list = $query->paginate($limit, false, ['page' => $page]);

        $currentPage = (int)$list->currentPage();
        $total = $list->total();
        $totalPages = max(1, (int)ceil($total / $limit));

        $extraParams = [];
        if (!empty($tag)) {
            $extraParams['tag'] = $tag;
        }
        if ($limit != 15) {
            $extraParams['limit'] = $limit;
        }

        $limitOptions = [15, 30, 50, 100];
        $selectHtml = '';
        foreach ($limitOptions as $opt) {
            $selected = $opt == $limit ? 'selected' : '';
            $selectHtml .= '<option value="' . $opt . '" ' . $selected . '>' . $opt . '</option>';
        }

        $html = Layout::begin('Hook管理', 'hook', 'hook');
        $html .= <<<HTML
    <div class="toolbar">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <h2>Hook管理</h2>
            <div class="search-box">
                <input type="text" id="searchTag" placeholder="输入 Tag 搜索" value="{$tag}" style="padding: 8px 12px; border: 1px solid #d9d9d9; border-radius: 4px; width: 200px; outline: none;" onkeydown="if(event.key==='Enter')doSearch()">
                <select id="limitSelect" onchange="doSearch()" style="padding: 8px 8px; margin-left: 8px; border: 1px solid #d9d9d9; border-radius: 4px; outline: none;">
                    <option value="">每页行数</option>
                    {$selectHtml}
                </select>
                <button class="btn btn-primary" onclick="doSearch()" style="margin-left: 8px;">搜索</button>
            </div>
            <button class="btn btn-danger" onclick="batchDelete()" style="display:none;" id="batchDelBtn">批量删除</button>
        </div>
    </div>
    <table>
        <thead>
            <tr>
                <th style="width:36px;"><input type="checkbox" id="checkAll" onchange="toggleAll(this)"></th>
                <th>ID</th>
                <th>Tag</th>
                <th>Branch</th>
                <th>备注</th>
                <th>模式</th>
                <th>方法</th>
                <th>域名</th>
                <th>状态</th>
                <th>创建时间</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
HTML;
        foreach ($list as $item) {
            $statusBtn = $item['status'] == 1
                ? '<button class="btn btn-sm status-toggle" data-id="' . $item['id'] . '" data-status="1" onclick="toggleStatus(this)" style="background:#f6ffed;color:#52c41a;border:1px solid #b7eb8f;border-radius:4px;padding:2px 8px;cursor:pointer;font-size:12px;">启用</button>'
                : '<button class="btn btn-sm status-toggle" data-id="' . $item['id'] . '" data-status="0" onclick="toggleStatus(this)" style="background:#fff2f0;color:#ff4d4f;border:1px solid #ffccc7;border-radius:4px;padding:2px 8px;cursor:pointer;font-size:12px;">禁用</button>';
            $modeText = $item['mode'] ?? '-';
            $methodText = $item['method'] ?? '-';
            $branchText = $item['branch'] ?: 'master';
            $remarkShort = mb_strlen($item['remark'] ?? '') > 20 ? mb_substr($item['remark'], 0, 20) . '...' : ($item['remark'] ?: '-');
            $html .= <<<ROW
            <tr>
                <td><input type="checkbox" class="row-checkbox" value="{$item['id']}" onchange="updateBatchBtn()"></td>
                <td>{$item['id']}</td>
                <td>{$item['tag']}</td>
                <td>{$branchText}</td>
                <td style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{$item['remark']}">{$remarkShort}</td>
                <td>{$modeText}</td>
                <td>{$methodText}</td>
                <td style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{$item['domain']}">{$item['domain']}</td>
                <td>{$statusBtn}</td>
                <td>{$item['date']}</td>
                <td>
                    <button class="btn btn-sm btn-edit" data-id="{$item['id']}" data-tag="{$item['tag']}" data-branch="{$item['branch']}" data-remark="{$item['remark']}" data-mode="{$item['mode']}" data-method="{$item['method']}" data-domain="{$item['domain']}" data-key="{$item['key']}" data-param="{$item['param']}" data-full_url="{$item['full_url']}" data-status="{$item['status']}" onclick="openEdit(this)">编辑</button>
                    <button class="btn btn-sm btn-del" onclick="doDelete({$item['id']})">删除</button>
                </td>
            </tr>
ROW;
        }
        $html .= <<<HTML
        </tbody>
    </table>
HTML;
        $html .= Layout::pagination($currentPage, $totalPages, '/admin/hook', $extraParams, $limit);
        $html .= <<<HTML
<script>
function doSearch() {
    var tag = document.getElementById('searchTag').value;
    var limit = document.getElementById('limitSelect').value;
    var url = '/admin/hook?page=1';
    if (tag) url += '&tag=' + encodeURIComponent(tag);
    if (limit) url += '&limit=' + limit;
    window.location.href = url;
}
function toggleAll(source) {
    var checkboxes = document.querySelectorAll('.row-checkbox');
    checkboxes.forEach(function(cb) { cb.checked = source.checked; });
    updateBatchBtn();
}
function updateBatchBtn() {
    var checked = document.querySelectorAll('.row-checkbox:checked');
    var btn = document.getElementById('batchDelBtn');
    btn.style.display = checked.length > 0 ? 'inline-block' : 'none';
    if (checked.length > 0) btn.textContent = '批量删除(' + checked.length + ')';
}
function batchDelete() {
    if (!confirm('确定要删除选中的Hook吗？')) return;
    var ids = [];
    document.querySelectorAll('.row-checkbox:checked').forEach(function(cb) { ids.push(cb.value); });
    if (ids.length === 0) return;
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '/admin/hook', true);
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
    xhr.send('batch_delete=1&ids=' + ids.join(','));
}
function toggleStatus(btn) {
    var id = btn.getAttribute('data-id');
    var currentStatus = parseInt(btn.getAttribute('data-status'));
    var newStatus = currentStatus === 1 ? 0 : 1;
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '/admin/hook', true);
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
    xhr.send('id=' + id + '&status=' + newStatus);
}
</script>
<div class="modal" id="hookModal">
    <div class="modal-box">
        <h3 id="modalTitle">编辑Hook</h3>
        <input type="hidden" id="editId" value="">
        <div class="form-group">
            <label>Tag</label>
            <input type="text" id="formTag" placeholder="请输入 Tag">
        </div>
        <div class="form-group">
            <label>Branch</label>
            <input type="text" id="formBranch" placeholder="请输入分支">
        </div>
        <div class="form-group">
            <label>备注</label>
            <input type="text" id="formRemark" placeholder="请输入备注">
        </div>
        <div class="form-group">
            <label>模式</label>
            <select id="formMode">
                <option value="direct">direct</option>
                <option value="aapanel">aapanel</option>
            </select>
        </div>
        <div class="form-group">
            <label>方法</label>
            <select id="formMethod">
                <option value="http">http</option>
                <option value="https">https</option>
                <option value="ssh">ssh</option>
            </select>
        </div>
        <div class="form-group">
            <label>域名</label>
            <input type="text" id="formDomain" placeholder="请输入域名">
        </div>
        <div class="form-group">
            <label>Key</label>
            <input type="text" id="formKey" placeholder="请输入 Key">
        </div>
        <div class="form-group">
            <label>参数</label>
            <input type="text" id="formParam" placeholder="请输入参数">
        </div>
        <div class="form-group">
            <label>完整URL</label>
            <input type="text" id="formFullUrl" placeholder="请输入完整URL">
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
    document.getElementById('hookModal').classList.remove('show');
}
function openEdit(btn) {
    document.getElementById('modalTitle').textContent = '编辑Hook';
    document.getElementById('editId').value = btn.getAttribute('data-id');
    document.getElementById('formTag').value = btn.getAttribute('data-tag');
    document.getElementById('formBranch').value = btn.getAttribute('data-branch');
    document.getElementById('formRemark').value = btn.getAttribute('data-remark');
    document.getElementById('formMode').value = btn.getAttribute('data-mode');
    document.getElementById('formMethod').value = btn.getAttribute('data-method');
    document.getElementById('formDomain').value = btn.getAttribute('data-domain');
    document.getElementById('formKey').value = btn.getAttribute('data-key');
    document.getElementById('formParam').value = btn.getAttribute('data-param');
    document.getElementById('formFullUrl').value = btn.getAttribute('data-full_url');
    document.getElementById('formStatus').value = btn.getAttribute('data-status');
    document.getElementById('hookModal').classList.add('show');
}
function submitForm() {
    var id = document.getElementById('editId').value;
    var tag = document.getElementById('formTag').value;
    var branch = document.getElementById('formBranch').value;
    var remark = document.getElementById('formRemark').value;
    var mode = document.getElementById('formMode').value;
    var method = document.getElementById('formMethod').value;
    var domain = document.getElementById('formDomain').value;
    var key = document.getElementById('formKey').value;
    var param = document.getElementById('formParam').value;
    var fullUrl = document.getElementById('formFullUrl').value;
    var status = document.getElementById('formStatus').value;

    var xhr = new XMLHttpRequest();
    xhr.open('POST', '/admin/hook', true);
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
    var params = 'id=' + id
        + '&tag=' + encodeURIComponent(tag)
        + '&branch=' + encodeURIComponent(branch)
        + '&remark=' + encodeURIComponent(remark)
        + '&mode=' + encodeURIComponent(mode)
        + '&method=' + encodeURIComponent(method)
        + '&domain=' + encodeURIComponent(domain)
        + '&key=' + encodeURIComponent(key)
        + '&param=' + encodeURIComponent(param)
        + '&full_url=' + encodeURIComponent(fullUrl)
        + '&status=' + status;
    xhr.send(params);
}
function doDelete(id) {
    if (!confirm('确定要删除该Hook吗？')) return;
    var xhr = new XMLHttpRequest();
    xhr.open('DELETE', '/admin/hook', true);
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

    private function update()
    {
        $id = Input::PostInt('id');
        $model = new AdminHookModel();
        $item = $model->findOrEmpty($id);

        if ($item->isEmpty()) {
            Ret::Fail(404, null, 'Hook不存在');
        }

        $data = [];
        if (request()->has('tag', 'post')) {
            $data['tag'] = Input::Post('tag');
        }
        if (request()->has('branch', 'post')) {
            $data['branch'] = Input::Post('branch');
        }
        if (request()->has('remark', 'post')) {
            $data['remark'] = Input::Post('remark', false);
        }
        if (request()->has('mode', 'post')) {
            $data['mode'] = Input::Post('mode');
        }
        if (request()->has('method', 'post')) {
            $data['method'] = Input::Post('method');
        }
        if (request()->has('domain', 'post')) {
            $data['domain'] = Input::Post('domain');
        }
        if (request()->has('key', 'post')) {
            $data['key'] = Input::Post('key', false);
        }
        if (request()->has('param', 'post')) {
            $data['param'] = Input::Post('param', false);
        }
        if (request()->has('full_url', 'post')) {
            $data['full_url'] = Input::Post('full_url', false);
        }
        if (request()->post('status') !== null) {
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

        $model = new AdminHookModel();
        $item = $model->findOrEmpty($id);

        if ($item->isEmpty()) {
            Ret::Fail(404, null, 'Hook不存在');
        }

        $item->delete();
        Ret::Success(0, [], '删除成功');
    }

    private function batchDelete()
    {
        $ids = Input::Post('ids', '');
        if (empty($ids)) {
            Ret::Fail(400, null, '未选择任何Hook');
        }

        $idArr = array_filter(array_map('intval', explode(',', $ids)));
        if (empty($idArr)) {
            Ret::Fail(400, null, '无效的ID');
        }

        $model = new AdminHookModel();
        $model->whereIn('id', $idArr)->delete();
        Ret::Success(0, [], '批量删除成功，共删除' . count($idArr) . '条');
    }
}