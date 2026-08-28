<?php

namespace app\admin\controller;

use app\admin\model\AdminAttachmentTokenModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;
use Input;
use Ret;

class AttachmentToken extends CommonController
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
        $md5 = input('get.md5', '', 'trim');

        $model = new AdminAttachmentTokenModel();
        $query = $model->where('id', '>', 0);
        if (!empty($md5)) {
            $query->where('token', 'like', "%{$md5}%");
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

        $html = Layout::begin('上传Token管理', 'storage', 'attachment_token');
        $html .= <<<HTML
    <div class="toolbar">
        <div style="display:flex;align-items:center;gap:12px;">
            <h2>上传Token管理</h2>
            <div class="search-box">
                <input type="text" id="searchToken" placeholder="输入 Token 搜索" value="{$md5}" style="padding: 8px 12px; border: 1px solid #d9d9d9; border-radius: 4px; width: 200px; outline: none;" onkeydown="if(event.key==='Enter')doSearch()">
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
                <th>Token</th>
                <th>OSS Token</th>
                <th>创建时间</th>
                <th>过期时间</th>
                <th>状态</th>
                <th>使用时间</th>
                <th>IP</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
HTML;
        foreach ($list as $item) {
            $statusBadge = $item['is_used'] == 1
                ? '<span class="status-badge" style="background:#f6ffed;color:#52c41a;border:1px solid #b7eb8f;">已使用</span>'
                : '<span class="status-badge" style="background:#fff7e6;color:#fa8c16;border:1px solid #ffd591;">未使用</span>';
            $usedTime = $item['update_time'] ?: '-';
            $ip = $item['ip'] ?: '-';
            $tokenShort = mb_strlen($item['token']) > 24 ? mb_substr($item['token'], 0, 24) . '...' : $item['token'];
            $ossTokenShort = mb_strlen($item['oss_token']) > 16 ? mb_substr($item['oss_token'], 0, 16) . '...' : $item['oss_token'];
            $html .= <<<ROW
            <tr>
                <td>{$item['id']}</td>
                <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{$item['token']}">{$tokenShort}</td>
                <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{$item['oss_token']}">{$ossTokenShort}</td>
                <td>{$item['created_at']}</td>
                <td>{$item['expired_at']}</td>
                <td>{$statusBadge}</td>
                <td>{$usedTime}</td>
                <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{$ip}">{$ip}</td>
                <td>
                    <button class="btn btn-sm btn-del" onclick="doDelete({$item['id']})">删除</button>
                </td>
            </tr>
ROW;
        }
        $html .= <<<HTML
        </tbody>
    </table>
HTML;
        $html .= Layout::pagination($currentPage, $totalPages, '/admin/attachment_token', $extraParams, $limit);
        $html .= <<<HTML
<script>
function doSearch() {
    var token = document.getElementById('searchToken').value;
    var limit = document.getElementById('limitSelect').value;
    var url = '/admin/attachment_token?page=1';
    if (token) url += '&md5=' + encodeURIComponent(token);
    if (limit) url += '&limit=' + limit;
    window.location.href = url;
}
function doDelete(id) {
    if (!confirm('确定要删除该Token记录吗？')) return;
    var xhr = new XMLHttpRequest();
    xhr.open('DELETE', '/admin/attachment_token', true);
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

    private function delete()
    {
        $id = intval(request()->delete('id'));
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminAttachmentTokenModel();
        $item = $model->findOrEmpty($id);

        if ($item->isEmpty()) {
            Ret::Fail(404, null, '记录不存在');
        }

        $item->delete();
        Ret::Success(0, [], '删除成功');
    }
}