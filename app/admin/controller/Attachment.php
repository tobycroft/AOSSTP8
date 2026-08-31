<?php

namespace app\admin\controller;

use app\admin\model\AdminAttachmentModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;
use Input;
use Ret;

class Attachment extends CommonController
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
            case 'POST':
                if (request()->post('batch_delete')) {
                    return $this->batchDelete();
                }
                return $this->update();
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

        $model = new AdminAttachmentModel();
        $query = $model->where('id', '>', 0);
        if (!empty($md5)) {
            $query->where('md5', 'like', "%{$md5}%");
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

        $items = [];
        foreach ($list as $item) {
            $size = $this->formatSize($item['size']);
            $items[] = [
                'id' => $item['id'],
                'name' => $item['name'],
                'mime' => $item['mime'],
                'size' => $size,
                'md5' => $item['md5'],
                'md5_short' => mb_strlen($item['md5']) > 32 ? mb_substr($item['md5'], 0, 32) . '...' : $item['md5'],
                'ip' => $item['ip'] ?: '-',
                'token' => $item['token'],
                'create_time' => $item['create_time'] ?: '-',
            ];
        }

        $pagination = Layout::pagination($currentPage, $totalPages, '/admin/attachment', $extraParams, $limit);

        return $this->renderPage('attachment/index', [
            'list' => $items,
            'md5' => $md5,
            'selectHtml' => $selectHtml,
            'pagination' => $pagination,
        ], '附件管理', 'storage', 'attachment');
    }

    private function update()
    {
        $id = Input::PostInt('id');
        $model = new AdminAttachmentModel();
        $item = $model->findOrEmpty($id);

        if ($item->isEmpty()) {
            Ret::Fail(404, null, '附件不存在');
        }

        $data = [];
        if (request()->has('name', 'post')) {
            $data['name'] = Input::Post('name');
        }
        if (request()->has('token', 'post')) {
            $data['token'] = Input::Post('token', false);
        }
        if (request()->has('ip', 'post')) {
            $data['ip'] = Input::Post('ip', false);
        }

        $item->save($data);
        Ret::Success(0, [], '更新成功');
    }

    private function delete()
    {
        $id = intval(request()->delete('id'));
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminAttachmentModel();
        $item = $model->findOrEmpty($id);

        if ($item->isEmpty()) {
            Ret::Fail(404, null, '附件不存在');
        }

        $item->delete();
        Ret::Success(0, [], '删除成功');
    }

    private function batchDelete()
    {
        $ids = Input::Post('ids', '');
        if (empty($ids)) {
            Ret::Fail(400, null, '未选择任何附件');
        }

        $idArr = array_filter(array_map('intval', explode(',', $ids)));
        if (empty($idArr)) {
            Ret::Fail(400, null, '无效的ID');
        }

        $model = new AdminAttachmentModel();
        $model->whereIn('id', $idArr)->delete();
        Ret::Success(0, [], '批量删除成功，共删除' . count($idArr) . '条');
    }

    private function formatSize($bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }
}