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

        $items = [];
        foreach ($list as $item) {
            $items[] = [
                'id' => $item['id'],
                'token' => $item['token'],
                'token_short' => mb_strlen($item['token']) > 24 ? mb_substr($item['token'], 0, 24) . '...' : $item['token'],
                'oss_token' => $item['oss_token'],
                'oss_token_short' => mb_strlen($item['oss_token']) > 16 ? mb_substr($item['oss_token'], 0, 16) . '...' : $item['oss_token'],
                'created_at' => $item['created_at'],
                'expired_at' => $item['expired_at'],
                'is_used' => $item['is_used'],
                'used_time' => $item['update_time'] ?: '-',
                'ip' => $item['ip'] ?: '-',
            ];
        }

        $pagination = Layout::pagination($currentPage, $totalPages, '/admin/attachment_token', $extraParams, $limit);

        return $this->renderPage('attachment_token/index', [
            'list' => $items,
            'md5' => $md5,
            'limit' => $limit,
            'limitOptions' => $limitOptions,
            'pagination' => $pagination,
        ], '上传Token管理', 'storage', 'attachment_token');
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