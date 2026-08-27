<?php

namespace app\admin\controller;

use app\admin\model\AdminCertLogModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;

class CertLog extends CommonController
{
    public function initialize()
    {
        parent::initialize();
        AdminAuth::requireLogin();
    }

    public function index()
    {
        $method = request()->method();
        if ($method !== 'GET') {
            \Ret::Fail(405, null, '不支持的请求方法');
        }
        return $this->page();
    }

    private function page()
    {
        $page = input('get.page', 1, 'intval');
        $limit = 20;

        $model = new AdminCertLogModel();
        $list = $model->order('id', 'desc')->paginate($limit, false, ['page' => $page]);

        $currentPage = (int)$list->currentPage();
        $total = $list->total();
        $totalPages = max(1, (int)ceil($total / $limit));

        $html = Layout::begin('操作日志', 'cert', 'cert_log');
        $html .= <<<HTML
    <h2 style="margin-bottom:16px;">操作日志</h2>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>AppName</th>
                <th>类型</th>
                <th>站点</th>
                <th>结果</th>
                <th>返回内容</th>
                <th>时间</th>
            </tr>
        </thead>
        <tbody>
HTML;
        foreach ($list as $item) {
            $resultBadge = $item['success'] == 1
                ? '<span class="status-badge success">成功</span>'
                : '<span class="status-badge fail">失败</span>';
            $recv = mb_strlen($item['recv']) > 60 ? mb_substr($item['recv'], 0, 60) . '...' : $item['recv'];
            $html .= <<<ROW
            <tr>
                <td>{$item['id']}</td>
                <td>{$item['appname']}</td>
                <td>{$item['type']}</td>
                <td>{$item['website']}</td>
                <td>{$resultBadge}</td>
                <td class="recv-cell" title="{$item['recv']}">{$recv}</td>
                <td>{$item['create_time']}</td>
            </tr>
ROW;
        }
        $html .= <<<HTML
        </tbody>
    </table>
HTML;
        $html .= Layout::pagination($currentPage, $totalPages, '/admin/cert_log');
        $html .= Layout::end();
        return response($html)->contentType('text/html');
    }
}