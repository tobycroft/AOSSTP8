<?php

namespace app\admin\controller;

use app\admin\model\AdminMailLogModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;
use Ret;

class MailLog extends CommonController
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
        $success = input('get.success', '');

        $model = new AdminMailLogModel();
        $query = $model->where('id', '>', 0);
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->whereOr('to_addr', 'like', "%{$search}%")
                  ->whereOr('subject', 'like', "%{$search}%")
                  ->whereOr('kid', 'like', "%{$search}%")
                  ->whereOr('server_name', 'like', "%{$search}%");
            });
        }
        if ($success === '1' || $success === '0') {
            $query->where('success', (int)$success);
        }
        $query->order('id', 'desc');
        $list = $query->paginate($limit, false, ['page' => $page]);

        $extraParams = [];
        if (!empty($search)) $extraParams['search'] = $search;
        if ($success !== '') $extraParams['success'] = $success;

        $items = [];
        foreach ($list as $item) {
            $items[] = [
                'id' => $item['id'],
                'kid' => $item['kid'] ?? '',
                'project_id' => $item['project_id'],
                'server_name' => $item['server_name'] ?? '',
                'to_addr' => $item['to_addr'] ?? '',
                'cc_addr' => $item['cc_addr'] ?? '',
                'subject' => $item['subject'] ?? '',
                'success' => $item['success'],
                'error' => $item['error'] ?? '',
                'date' => $item['date'] ?? '',
            ];
        }

        $pagination = Layout::pagination((int)$list->currentPage(), max(1, (int)ceil($list->total() / $limit)), '/admin/mail_log', $extraParams, $limit);

        return $this->renderPage('mail_log/index', [
            'list' => $items,
            'search' => $search,
            'success' => $success,
            'pagination' => $pagination,
        ], '发送日志', 'mail', 'mail_log');
    }

    private function delete()
    {
        $id = intval(request()->delete('id'));
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }
        $model = new AdminMailLogModel();
        $item = $model->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '日志不存在');
        }
        $item->delete();
        Ret::Success(0, [], '删除成功');
    }
}
