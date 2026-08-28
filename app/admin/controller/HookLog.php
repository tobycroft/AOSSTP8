<?php

namespace app\admin\controller;

use app\admin\model\AdminHookLogModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;
use Input;
use Ret;

class HookLog extends CommonController
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

        $model = new AdminHookLogModel();
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

        $html = Layout::begin('Hook日志', 'hook', 'hook_log');
        $html .= <<<HTML
    <div class="toolbar">
        <div style="display:flex;align-items:center;gap:12px;">
            <h2>Hook日志</h2>
            <div class="search-box">
                <input type="text" id="searchTag" placeholder="输入 Tag 搜索" value="{$tag}" style="padding: 8px 12px; border: 1px solid #d9d9d9; border-radius: 4px; width: 200px; outline: none;" onkeydown="if(event.key==='Enter')doSearch()">
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
                <th>Tag</th>
                <th>备注</th>
                <th>状态</th>
                <th>URL</th>
                <th>接收内容</th>
                <th>创建时间</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
HTML;
        foreach ($list as $item) {
            $statusBadge = $item['success'] == 1
                ? '<span class="status-badge" style="background:#f6ffed;color:#52c41a;border:1px solid #b7eb8f;">成功</span>'
                : '<span class="status-badge" style="background:#fff2f0;color:#ff4d4f;border:1px solid #ffccc7;">失败</span>';
            $remarkShort = mb_strlen($item['remark'] ?? '') > 20 ? mb_substr($item['remark'], 0, 20) . '...' : ($item['remark'] ?: '-');
            $urlShort = mb_strlen($item['url'] ?? '') > 30 ? mb_substr($item['url'], 0, 30) . '...' : ($item['url'] ?: '-');
            $recvShort = mb_strlen($item['recv'] ?? '') > 20 ? mb_substr($item['recv'], 0, 20) . '...' : ($item['recv'] ?: '-');
            $html .= <<<ROW
            <tr>
                <td>{$item['id']}</td>
                <td>{$item['tag']}</td>
                <td style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{$item['remark']}">{$remarkShort}</td>
                <td>{$statusBadge}</td>
                <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{$item['url']}">{$urlShort}</td>
                <td style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{$item['recv']}">{$recvShort}</td>
                <td>{$item['date']}</td>
                <td>
                    <button class="btn btn-sm btn-edit" onclick="openDetail({$item['id']}, '{$item['url']}', '{$item['recv']}')">详情</button>
                    <button class="btn btn-sm btn-del" onclick="doDelete({$item['id']})">删除</button>
                </td>
            </tr>
ROW;
        }
        $html .= <<<HTML
        </tbody>
    </table>
HTML;
        $html .= Layout::pagination($currentPage, $totalPages, '/admin/hook_log', $extraParams, $limit);
        $html .= <<<HTML
<script>
function doSearch() {
    var tag = document.getElementById('searchTag').value;
    var limit = document.getElementById('limitSelect').value;
    var url = '/admin/hook_log?page=1';
    if (tag) url += '&tag=' + encodeURIComponent(tag);
    if (limit) url += '&limit=' + limit;
    window.location.href = url;
}
function doDelete(id) {
    if (!confirm('确定要删除该日志吗？')) return;
    var xhr = new XMLHttpRequest();
    xhr.open('DELETE', '/admin/hook_log', true);
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
<div class="modal" id="detailModal">
    <div class="modal-box" style="width: 680px;">
        <h3>日志详情</h3>
        <div class="form-group">
            <label>URL</label>
            <textarea id="detailUrl" readonly style="width:100%;padding:8px 10px;border:1px solid #d9d9d9;border-radius:4px;font-size:14px;outline:none;min-height:60px;resize:vertical;"></textarea>
        </div>
        <div class="form-group">
            <label>接收内容</label>
            <textarea id="detailRecv" readonly style="width:100%;padding:8px 10px;border:1px solid #d9d9d9;border-radius:4px;font-size:14px;outline:none;min-height:120px;resize:vertical;"></textarea>
        </div>
        <div class="modal-actions">
            <button class="btn btn-cancel" onclick="closeDetail()">关闭</button>
        </div>
    </div>
</div>

<script>
function closeDetail() {
    document.getElementById('detailModal').classList.remove('show');
}
function openDetail(id, url, recv) {
    document.getElementById('detailUrl').value = url;
    document.getElementById('detailRecv').value = recv;
    document.getElementById('detailModal').classList.add('show');
}
</script>
HTML;
        $html .= Layout::end();
        return response($html)->contentType('text/html');
    }

    private function delete()
    {
        $id = intval(request()->delete('id'));
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminHookLogModel();
        $item = $model->findOrEmpty($id);

        if ($item->isEmpty()) {
            Ret::Fail(404, null, '日志不存在');
        }

        $item->delete();
        Ret::Success(0, [], '删除成功');
    }
}