<?php

namespace app\admin\controller;

use app\admin\model\AdminLoginLogModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;
use Ret;

class LoginLog extends CommonController
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

        $model = new AdminLoginLogModel();
        $query = $model->where('id', '>', 0);
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->whereOr('username', 'like', "%{$search}%")
                    ->whereOr('ip', 'like', "%{$search}%");
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

        $items = [];
        foreach ($list as $item) {
            $items[] = [
                'id' => $item['id'],
                'user_id' => $item['user_id'],
                'username' => $item['username'] ?? '',
                'ip' => $item['ip'] ?? '',
                'success' => $item['success'],
                'password' => $item['password'] ?? '',
                'reason' => $item['reason'] ?? '',
                'create_time' => $item['create_time'] ?? '',
            ];
        }

        $pagination = Layout::pagination($currentPage, $totalPages, '/admin/login_log', $extraParams, $limit);

        return $this->renderPage('login_log/index', [
            'list' => $items,
            'search' => $search,
            'pagination' => $pagination,
        ], '登录日志', 'admin', 'login_log');
    }

    private function delete()
    {
        $id = intval(request()->delete('id'));
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminLoginLogModel();
        $item = $model->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '日志不存在');
        }

        $item->delete();
        Ret::Success(0, [], '删除成功');
    }
}
