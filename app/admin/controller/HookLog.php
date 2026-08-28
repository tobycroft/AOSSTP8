<?php

namespace app\admin\controller;

use app\admin\model\AdminHookLogModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;
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
            </tr>
        </thead>
        <tbody>
HTML;
        foreach ($list as $item) {
            $statusBadge = $item['success'] == 1
                ? '<span class="status-badge" style="background:#f6ffed;color:#52c41a;border:1px solid #b7eb8f;">成功</span>'
                : '<span class="status-badge" style="background:#fff2f0;color:#ff4d4f;border:1px solid #ffccc7;">失败</span>';
            $remarkShort = mb_strlen($item['remark'] ?? '') > 20 ? mb_substr($item['remark'], 0, 20) . '...' : ($item['remark'] ?: '-');
            $url = $item['url'] ?? '';
            $urlShort = mb_strlen($url) > 30 ? mb_substr($url, 0, 30) . '...' : ($url ?: '-');
            $urlAttr = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            $recv = $item['recv'] ?? '';
            $recvShort = mb_strlen($recv) > 20 ? mb_substr($recv, 0, 20) . '...' : ($recv ?: '-');
            $recvAttr = htmlspecialchars($recv, ENT_QUOTES, 'UTF-8');
            $html .= <<<ROW
            <tr>
                <td>{$item['id']}</td>
                <td>{$item['tag']}</td>
                <td style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{$item['remark']}">{$remarkShort}</td>
                <td>{$statusBadge}</td>
                <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;cursor:pointer;position:relative;" class="recv-cell" data-url="{$urlAttr}" onmouseenter="showTooltip(this)" onmouseleave="hideTooltip(this)">{$urlShort}</td>
                <td style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;cursor:pointer;position:relative;" class="recv-cell" data-recv="{$recvAttr}" onmouseenter="showTooltip(this)" onmouseleave="hideTooltip(this)">{$recvShort}</td>
                <td>{$item['date']}</td>
            </tr>
ROW;
        }
        $html .= <<<HTML
        </tbody>
    </table>
HTML;
        $html .= Layout::pagination($currentPage, $totalPages, '/admin/hook_log', $extraParams, $limit);
        $html .= <<<HTML
<div id="tooltipBox" style="display:none;position:fixed;background:rgba(0, 0, 0, 0.85);color:#fff;padding:8px 12px;border-radius:4px;font-size:12px;white-space:pre-wrap;word-break:break-word;max-width:400px;z-index:9999;box-shadow:0 2px 8px rgba(0,0,0,0.15);"></div>
<style>
.recv-cell {
    cursor: pointer;
    position: relative;
}
</style>
<script>
function doSearch() {
    var tag = document.getElementById('searchTag').value;
    var limit = document.getElementById('limitSelect').value;
    var url = '/admin/hook_log?page=1';
    if (tag) url += '&tag=' + encodeURIComponent(tag);
    if (limit) url += '&limit=' + limit;
    window.location.href = url;
}
var tooltipBox = document.getElementById('tooltipBox');
function showTooltip(el) {
    var content = el.getAttribute('data-url') || el.getAttribute('data-recv');
    if (!content) return;
    var rect = el.getBoundingClientRect();
    tooltipBox.style.display = 'block';
    tooltipBox.style.left = (rect.left) + 'px';
    tooltipBox.style.bottom = (window.innerHeight - rect.top + 8) + 'px';
    tooltipBox.textContent = content;
}
function hideTooltip() {
    tooltipBox.style.display = 'none';
}
</script>
HTML;
        $html .= Layout::end();
        return response($html)->contentType('text/html');
    }
}