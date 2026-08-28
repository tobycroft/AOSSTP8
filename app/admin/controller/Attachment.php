<?php

namespace app\admin\controller;

use app\admin\model\AdminAttachmentModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;
use Input;
use Ret;

class Attachment extends CommonController
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
            case 'DELETE':
                return $this->delete();
        }
        Ret::Fail(405, null, '不支持的请求方法');
    }

    private function page()
    {
        $page = input('get.page', 1, 'intval');
        $limit = input('get.limit', 15, 'intval');
        $md5 = input('get.md5', '', 'trim');

        $model = new AdminAttachmentModel();
        $query = $model->where('id', '>', 0);
        if (!empty($md5)) {
            $query->where('md5', 'like', "%{$md5}%");
        }
        $query->order('id', 'desc');
        $list = $query->paginate($limit, false, ['page' => $page]);

        $currentPage = (int)$list->currentPage();
        $total = $list->total();
        $totalPages = max(1, (int)ceil($total / $limit));

        $extraParams = [];
        if (!empty($md5)) {
            $extraParams['md5'] = $md5;
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

        $html = Layout::begin('附件管理', 'storage', 'attachment');
        $html .= <<<HTML
    <div class="toolbar">
        <div style="display:flex;align-items:center;gap:12px;">
            <h2>附件管理</h2>
            <div class="search-box">
                <input type="text" id="searchMd5" placeholder="输入 MD5 搜索" value="{$md5}" style="padding: 8px 12px; border: 1px solid #d9d9d9; border-radius: 4px; width: 200px; outline: none;" onkeydown="if(event.key==='Enter')doSearch()">
                <select id="limitSelect" onchange="doSearch()" style="padding: 8px 8px; margin-left: 8px; border: 1px solid #d9d9d9; border-radius: 4px; outline: none;">
                    <option value="">每页行数</option>
                    {$selectHtml}
                </select>
                <button class="btn btn-primary" onclick="doSearch()" style="margin-left: 8px;">搜索</button>
            </div>
        </div>
    </div>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>文件名</th>
                <th>MIME</th>
                <th>大小</th>
                <th>扩展名</th>
                <th>MD5</th>
                <th>宽x高</th>
                <th>时长</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
HTML;
        foreach ($list as $item) {
            $size = $this->formatSize($item['size']);
            $wh = ($item['width'] > 0 && $item['height'] > 0) ? $item['width'] . 'x' . $item['height'] : '-';
            $duration = $item['duration_str'] ?: '-';
            $md5Short = mb_strlen($item['md5']) > 16 ? mb_substr($item['md5'], 0, 16) . '...' : $item['md5'];
            $html .= <<<ROW
            <tr>
                <td>{$item['id']}</td>
                <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{$item['name']}">{$item['name']}</td>
                <td style="max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{$item['mime']}">{$item['mime']}</td>
                <td>{$size}</td>
                <td>{$item['ext']}</td>
                <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{$item['md5']}">{$md5Short}</td>
                <td>{$wh}</td>
                <td>{$duration}</td>
                <td>
                    <button class="btn btn-sm btn-edit" onclick="openEdit({$item['id']}, '{$item['name']}', '{$item['token']}')">编辑</button>
                    <button class="btn btn-sm btn-del" onclick="doDelete({$item['id']})">删除</button>
                </td>
            </tr>
ROW;
        }
        $html .= <<<HTML
        </tbody>
    </table>
HTML;
        $html .= Layout::pagination($currentPage, $totalPages, '/admin/attachment', $extraParams, $limit);
        $html .= <<<HTML
<script>
function doSearch() {
    var md5 = document.getElementById('searchMd5').value;
    var limit = document.getElementById('limitSelect').value;
    var url = '/admin/attachment?page=1';
    if (md5) url += '&md5=' + encodeURIComponent(md5);
    if (limit) url += '&limit=' + limit;
    window.location.href = url;
}
</script>
<div class="modal" id="attachModal">
    <div class="modal-box">
        <h3 id="modalTitle">编辑附件</h3>
        <input type="hidden" id="editId" value="">
        <div class="form-group">
            <label>文件名</label>
            <input type="text" id="formName" placeholder="请输入文件名">
        </div>
        <div class="form-group">
            <label>Token</label>
            <input type="text" id="formToken" placeholder="请输入 Token">
        </div>
        <div class="modal-actions">
            <button class="btn btn-cancel" onclick="closeModal()">取消</button>
            <button class="btn btn-primary" onclick="submitForm()">确定</button>
        </div>
    </div>
</div>

<script>
function closeModal() {
    document.getElementById('attachModal').classList.remove('show');
}
function openEdit(id, name, token) {
    document.getElementById('modalTitle').textContent = '编辑附件';
    document.getElementById('editId').value = id;
    document.getElementById('formName').value = name;
    document.getElementById('formToken').value = token;
    document.getElementById('attachModal').classList.add('show');
}
function submitForm() {
    var id = document.getElementById('editId').value;
    var name = document.getElementById('formName').value;
    var token = document.getElementById('formToken').value;

    var xhr = new XMLHttpRequest();
    xhr.open('POST', '/admin/attachment', true);
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
    var params = 'id=' + id + '&name=' + encodeURIComponent(name) + '&token=' + encodeURIComponent(token);
    xhr.send(params);
}
function doDelete(id) {
    if (!confirm('确定要删除该附件吗？')) return;
    var xhr = new XMLHttpRequest();
    xhr.open('DELETE', '/admin/attachment', true);
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
        $model = new AdminAttachmentModel();
        $item = $model->findOrEmpty($id);

        if ($item->isEmpty()) {
            Ret::Fail(404, null, '附件不存在');
        }

        $data = [];
        if (request()->has('name', 'post')) {
            $data['name'] = Input::Post('name');
        }
        if (request()->has('token', 'post')) {
            $data['token'] = Input::Post('token', false);
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

        $model = new AdminAttachmentModel();
        $item = $model->findOrEmpty($id);

        if ($item->isEmpty()) {
            Ret::Fail(404, null, '附件不存在');
        }

        $item->delete();
        Ret::Success(0, [], '删除成功');
    }

    private function formatSize($bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }
}