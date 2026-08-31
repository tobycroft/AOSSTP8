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

        $items = [];
        foreach ($list as $item) {
            $items[] = [
                'id' => $item['id'],
                'tag' => $item['tag'],
                'remark' => $item['remark'] ?? '',
                'remark_short' => mb_strlen($item['remark'] ?? '') > 20 ? mb_substr($item['remark'], 0, 20) . '...' : ($item['remark'] ?: '-'),
                'success' => $item['success'],
                'url' => $item['url'] ?? '',
                'url_short' => '',
                'url_attr' => '',
                'recv' => $item['recv'] ?? '',
                'recv_short' => '',
                'recv_attr' => '',
                'date' => $item['date'],
            ];
            $url = $item['url'] ?? '';
            $items[count($items) - 1]['url_short'] = mb_strlen($url) > 30 ? mb_substr($url, 0, 30) . '...' : ($url ?: '-');
            $items[count($items) - 1]['url_attr'] = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            $recv = $item['recv'] ?? '';
            $items[count($items) - 1]['recv_short'] = mb_strlen($recv) > 20 ? mb_substr($recv, 0, 20) . '...' : ($recv ?: '-');
            $items[count($items) - 1]['recv_attr'] = htmlspecialchars($recv, ENT_QUOTES, 'UTF-8');
        }

        $pagination = Layout::pagination($currentPage, $totalPages, '/admin/hook_log', $extraParams, $limit);

        return $this->renderPage('hook_log/index', [
            'list' => $items,
            'tag' => $tag,
            'limit' => $limit,
            'limitOptions' => $limitOptions,
            'pagination' => $pagination,
        ], 'Hook日志', 'hook', 'hook_log');
    }
}