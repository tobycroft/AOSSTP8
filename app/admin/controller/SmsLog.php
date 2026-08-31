<?php

namespace app\admin\controller;

use app\admin\model\AdminSmsLogModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;
use Input;
use Ret;

class SmsLog extends CommonController
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
        $limit = 15;
        $search = input('get.search', '', 'trim');

        $model = new AdminSmsLogModel();
        $query = $model->where('id', '>', 0);
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->whereOr('name', 'like', "%{$search}%")
                  ->whereOr('phone', 'like', "%{$search}%");
            });
        }
        $query->order('id', 'desc');
        $list = $query->paginate($limit, false, ['page' => $page]);

        $currentPage = (int)$list->currentPage();
        $total = $list->total();
        $totalPages = max(1, (int)ceil($total / $limit));

        $extraParams = [];
        if (!empty($search)) {
            $extraParams['search'] = $search;
        }

        $limitOptions = [15, 30, 50, 100];

        $items = [];
        foreach ($list as $item) {
            $items[] = [
                'id' => $item['id'],
                'name' => $item['name'] ?? '',
                'phone' => $item['phone'] ?? '',
                'success' => $item['success'],
                'error' => $item['error'] ?? '',
                'ip' => $item['ip'] ?? '',
                'date' => $item['date'] ?? '',
            ];
        }

        $pagination = Layout::pagination($currentPage, $totalPages, '/admin/sms_log', $extraParams, $limit);

        return $this->renderPage('sms_log/index', [
            'list' => $items,
            'search' => $search,
            'limit' => $limit,
            'limitOptions' => $limitOptions,
            'pagination' => $pagination,
        ], '发送日志', 'sms', 'sms_log');
    }

    private function delete()
    {
        $id = intval(request()->delete('id'));
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminSmsLogModel();
        $item = $model->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '日志不存在');
        }

        $item->delete();
        Ret::Success(0, [], '删除成功');
    }
}