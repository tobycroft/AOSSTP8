<?php

namespace app\admin\controller;

use app\admin\model\AdminHookModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;
use Input;
use Ret;

class Hook extends CommonController
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
        $tag = input('get.tag', '', 'trim');

        $model = new AdminHookModel();
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

        $items = [];
        foreach ($list as $item) {
            $items[] = [
                'id' => $item['id'],
                'tag' => $item['tag'],
                'branch' => $item['branch'] ?: 'master',
                'branch_text' => $item['branch'] ?: 'master',
                'remark' => $item['remark'] ?? '',
                'remark_short' => mb_strlen($item['remark'] ?? '') > 20 ? mb_substr($item['remark'], 0, 20) . '...' : ($item['remark'] ?: '-'),
                'mode' => $item['mode'] ?? '',
                'mode_text' => $item['mode'] ?? '-',
                'method' => $item['method'] ?? '',
                'method_text' => $item['method'] ?? '-',
                'domain' => $item['domain'],
                'date' => $item['date'],
                'key' => $item['key'],
                'param' => $item['param'],
                'full_url' => $item['full_url'],
                'status' => $item['status'],
            ];
        }

        $pagination = Layout::pagination($currentPage, $totalPages, '/admin/hook', $extraParams, $limit);

        return $this->renderPage('hook/index', [
            'list' => $items,
            'tag' => $tag,
            'selectHtml' => $selectHtml,
            'pagination' => $pagination,
        ], 'Hook管理', 'hook', 'hook');
    }

    private function update()
    {
        $id = Input::PostInt('id');
        $model = new AdminHookModel();
        $item = $model->findOrEmpty($id);

        if ($item->isEmpty()) {
            Ret::Fail(404, null, 'Hook不存在');
        }

        $data = [];
        if (request()->has('tag', 'post')) {
            $data['tag'] = Input::Post('tag');
        }
        if (request()->has('branch', 'post')) {
            $data['branch'] = Input::Post('branch');
        }
        if (request()->has('remark', 'post')) {
            $data['remark'] = Input::Post('remark', false);
        }
        if (request()->has('mode', 'post')) {
            $data['mode'] = Input::Post('mode');
        }
        if (request()->has('method', 'post')) {
            $data['method'] = Input::Post('method');
        }
        if (request()->has('domain', 'post')) {
            $data['domain'] = Input::Post('domain');
        }
        if (request()->has('key', 'post')) {
            $data['key'] = Input::Post('key', false);
        }
        if (request()->has('param', 'post')) {
            $data['param'] = Input::Post('param', false);
        }
        if (request()->has('full_url', 'post')) {
            $data['full_url'] = Input::Post('full_url', false);
        }
        if (request()->has('status', 'post')) {
            $data['status'] = Input::PostInt('status');
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

        $model = new AdminHookModel();
        $item = $model->findOrEmpty($id);

        if ($item->isEmpty()) {
            Ret::Fail(404, null, 'Hook不存在');
        }

        $item->delete();
        Ret::Success(0, [], '删除成功');
    }

    private function batchDelete()
    {
        $ids = Input::Post('ids', '');
        if (empty($ids)) {
            Ret::Fail(400, null, '未选择任何Hook');
        }

        $idArr = array_filter(array_map('intval', explode(',', $ids)));
        if (empty($idArr)) {
            Ret::Fail(400, null, '无效的ID');
        }

        $model = new AdminHookModel();
        $model->whereIn('id', $idArr)->delete();
        Ret::Success(0, [], '批量删除成功，共删除' . count($idArr) . '条');
    }
}