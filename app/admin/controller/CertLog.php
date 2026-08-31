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

        $items = [];
        foreach ($list as $item) {
            $items[] = [
                'id' => $item['id'],
                'appname' => $item['appname'],
                'type' => $item['type'],
                'website' => $item['website'],
                'result_badge' => $item['success'] == 1
                    ? '<span class="status-badge success">成功</span>'
                    : '<span class="status-badge fail">失败</span>',
                'recv' => $item['recv'],
                'recv_short' => mb_strlen($item['recv']) > 60 ? mb_substr($item['recv'], 0, 60) . '...' : $item['recv'],
                'create_time' => $item['create_time'],
            ];
        }

        $pagination = Layout::pagination($currentPage, $totalPages, '/admin/cert_log');

        return $this->renderPage('cert_log/index', [
            'list' => $items,
            'pagination' => $pagination,
        ], '操作日志', 'cert', 'cert_log');
    }
}