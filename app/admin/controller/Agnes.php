<?php

namespace app\admin\controller;

use app\admin\model\AdminAgnesModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use app\v3\ai\action\AiAction;
use BaseController\CommonController;
use Input;
use Ret;

/**
 * Agnes API 管理：单独维护上游密钥/地址/模型，供多个 v3 项目绑定复用。
 */
class Agnes extends CommonController
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
                return $this->update();
            case 'PUT':
                return $this->create();
            case 'DELETE':
                return $this->delete();
        }
        Ret::Fail(405, null, '不支持的请求方法');
    }

    private function page()
    {
        $page = input('get.page', 1, 'intval');
        $limit = 15;
        $search = input('get.search', '');

        $model = new AdminAgnesModel();
        $query = $model->order('id', 'desc');
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%');
                $q->whereOr('api_key', 'like', '%' . $search . '%');
            });
        }
        $list = $query->paginate($limit, false, ['page' => $page])->toArray();

        $items = [];
        foreach ($list['data'] as $item) {
            $apiKey = (string)($item['api_key'] ?? '');
            $items[] = [
                'id' => $item['id'],
                'name' => $item['name'],
                'api_key' => $apiKey,
                'api_key_short' => mb_strlen($apiKey) > 16 ? mb_substr($apiKey, 0, 8) . '...' . mb_substr($apiKey, -4) : $apiKey,
                'base_url' => $item['base_url'],
                'model' => $item['model'],
                'status' => $item['status'],
                'bound_count' => AdminAgnesModel::boundCount((int)$item['id']),
            ];
        }

        $pagination = Layout::pagination((int)$list['current_page'], max(1, (int)ceil($list['total'] / $limit)), '/admin/agnes', ['search' => $search], $limit);

        return $this->renderPage('agnes/index', [
            'list' => $items,
            'search' => $search,
            'pagination' => $pagination,
        ], 'Agnes API', 'ai', 'agnes');
    }

    private function create()
    {
        $name = Input::Put('name');
        if (empty($name)) {
            Ret::Fail(400, null, 'API 名称不能为空');
        }
        $apiKey = Input::Put('api_key', '');
        if (empty($apiKey)) {
            Ret::Fail(400, null, 'API 密钥不能为空');
        }

        AdminAgnesModel::create([
            'name' => $name,
            'api_key' => $apiKey,
            'base_url' => Input::Put('base_url', AiAction::getDefaultBaseUrl()),
            'model' => Input::Put('model', AiAction::getDefaultModel()),
            'status' => Input::PutInt('status', 1),
        ]);
        Ret::Success(0, [], '创建成功');
    }

    private function update()
    {
        $id = Input::PostInt('id');
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminAgnesModel();
        $item = $model->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, 'Agnes API 不存在');
        }

        $data = [];
        if (request()->has('name', 'post')) $data['name'] = Input::Post('name');
        if (request()->has('api_key', 'post')) $data['api_key'] = Input::Post('api_key');
        if (request()->has('base_url', 'post')) $data['base_url'] = Input::Post('base_url');
        if (request()->has('model', 'post')) $data['model'] = Input::Post('model');
        if (request()->has('status', 'post')) $data['status'] = Input::PostInt('status');

        $item->save($data);
        Ret::Success(0, [], '更新成功');
    }

    /**
     * 复制一条 Agnes API 为新记录（名称追加"-副本"）。
     * Agnes API 本身不含 v3 凭证，复制是安全的；同一把上游密钥可在后台被多个项目绑定。
     */
    public function copy()
    {
        $id = Input::PostInt('id');
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminAgnesModel();
        $item = $model->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, 'Agnes API 不存在');
        }

        $row = $item->toArray();
        unset($row['id'], $row['date']);
        $row['name'] = ($row['name'] ?? '') . '-副本';
        AdminAgnesModel::create($row);
        Ret::Success(0, [], '复制成功');
    }

    private function delete()
    {
        $id = intval(request()->delete('id'));
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $bound = AdminAgnesModel::boundCount($id);
        if ($bound > 0) {
            Ret::Fail(400, null, '该 Agnes API 仍被 ' . $bound . ' 个项目绑定，请先改绑后再删除');
        }

        $model = new AdminAgnesModel();
        $item = $model->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, 'Agnes API 不存在');
        }

        $item->delete();
        Ret::Success(0, [], '删除成功');
    }
}
