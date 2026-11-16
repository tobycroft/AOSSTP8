<?php

namespace app\admin\controller;

use app\admin\model\AdminAiModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;
use Input;
use Ret;

class Ai extends CommonController
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

        $model = new AdminAiModel();
        $query = $model->order('id', 'desc');
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')->whereOr('project', 'like', '%' . $search . '%');
            });
        }
        $list = $query->paginate($limit, false, ['page' => $page])->toArray();

        $items = [];
        foreach ($list['data'] as $item) {
            $items[] = [
                'id' => $item['id'],
                'name' => $item['name'],
                'project' => $item['project'],
                'base_url' => $item['base_url'],
                'model' => $item['model'],
                'api_key' => $item['api_key'],
                'api_key_short' => mb_strlen($item['api_key']) > 16 ? mb_substr($item['api_key'], 0, 8) . '...' . mb_substr($item['api_key'], -4) : $item['api_key'],
                'status' => $item['status'],
            ];
        }

        $pagination = Layout::pagination((int)$list['current_page'], max(1, (int)ceil($list['total'] / $limit)), '/admin/ai', ['search' => $search], $limit);

        return $this->renderPage('ai/index', [
            'list' => $items,
            'search' => $search,
            'pagination' => $pagination,
        ], 'AI接口配置', 'ai', 'ai');
    }

    private function create()
    {
        $name = Input::Put('name');
        if (empty($name)) {
            Ret::Fail(400, null, '项目名称不能为空');
        }
        $project = Input::Put('project');
        if (empty($project)) {
            Ret::Fail(400, null, '项目Token不能为空');
        }

        $data = [
            'name' => $name,
            'project' => $project,
            'api_key' => Input::Put('api_key', ''),
            'base_url' => Input::Put('base_url', 'https://apihub.agnes-ai.com/v1'),
            'model' => Input::Put('model', 'agnes-3.0-flash'),
            'status' => Input::PutInt('status', 1),
        ];

        AdminAiModel::create($data);
        Ret::Success(0, [], '创建成功');
    }

    private function update()
    {
        $id = Input::PostInt('id');
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminAiModel();
        $item = $model->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '项目不存在');
        }

        $data = [];
        if (request()->has('name', 'post')) $data['name'] = Input::Post('name');
        if (request()->has('project', 'post')) $data['project'] = Input::Post('project');
        if (request()->has('api_key', 'post')) $data['api_key'] = Input::Post('api_key');
        if (request()->has('base_url', 'post')) $data['base_url'] = Input::Post('base_url');
        if (request()->has('model', 'post')) $data['model'] = Input::Post('model');
        if (request()->has('status', 'post')) $data['status'] = Input::PostInt('status');

        $item->save($data);
        Ret::Success(0, [], '更新成功');
    }

    private function delete()
    {
        $id = intval(request()->delete('id'));
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminAiModel();
        $item = $model->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '项目不存在');
        }

        $item->delete();
        Ret::Success(0, [], '删除成功');
    }
}