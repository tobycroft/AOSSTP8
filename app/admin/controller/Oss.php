<?php

namespace app\admin\controller;

use app\admin\model\AdminOssModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;
use Input;
use Ret;

class Oss extends CommonController
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

        $model = new AdminOssModel();
        $query = $model->order('id', 'desc');
        if (!empty($search)) {
            $query->where('name', 'like', '%' . $search . '%');
        }
        $list = $query->paginate($limit, false, ['page' => $page])->toArray();

        $items = [];
        foreach ($list['data'] as $item) {
            $items[] = [
                'id' => $item['id'],
                'name' => $item['name'],
                'type' => $item['type'],
                'main_type' => $item['main_type'],
                'code' => $item['code'],
                'token' => $item['token'],
                'token_short' => mb_strlen($item['token']) > 16 ? mb_substr($item['token'], 0, 16) . '...' : $item['token'],
                'oss_type' => $item['oss_type'],
                'oss_tag' => $item['oss_tag'],
                'url' => $item['url'],
                'ext' => $item['ext'],
                'size' => $item['size'],
                'status' => $item['status'],
            ];
        }

        $pagination = Layout::pagination((int)$list['current_page'], max(1, (int)ceil($list['total'] / $limit)), '/admin/oss', ['search' => $search], $limit);

        return $this->renderPage('oss/index', [
            'list' => $items,
            'search' => $search,
            'pagination' => $pagination,
        ], '存储项目', 'storage', 'oss');
    }

    private function create()
    {
        $name = Input::Put('name');
        if (empty($name)) {
            Ret::Fail(400, null, '项目名称不能为空');
        }

        $data = [
            'name' => $name,
            'type' => Input::Put('type', 'local'),
            'main_type' => Input::Put('main_type', 'local'),
            'code' => Input::Put('code', ''),
            'token' => Input::Put('token', ''),
            'oss_type' => Input::Put('oss_type', 'none'),
            'oss_tag' => Input::Put('oss_tag', 'default'),
            'url' => Input::Put('url', ''),
            'ext' => Input::Put('ext', 'jpg,png,gif,bmp,jpeg'),
            'size' => Input::PutInt('size', 16384),
            'status' => Input::PutInt('status', 1),
        ];

        AdminOssModel::create($data);
        Ret::Success(0, [], '创建成功');
    }

    private function update()
    {
        $id = Input::PostInt('id');
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminOssModel();
        $item = $model->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '项目不存在');
        }

        $data = [];
        if (request()->has('name', 'post')) $data['name'] = Input::Post('name');
        if (request()->has('type', 'post')) $data['type'] = Input::Post('type');
        if (request()->has('main_type', 'post')) $data['main_type'] = Input::Post('main_type');
        if (request()->has('code', 'post')) $data['code'] = Input::Post('code');
        if (request()->has('token', 'post')) $data['token'] = Input::Post('token');
        if (request()->has('oss_type', 'post')) $data['oss_type'] = Input::Post('oss_type');
        if (request()->has('oss_tag', 'post')) $data['oss_tag'] = Input::Post('oss_tag');
        if (request()->has('url', 'post')) $data['url'] = Input::Post('url');
        if (request()->has('ext', 'post')) $data['ext'] = Input::Post('ext');
        if (request()->has('size', 'post')) $data['size'] = Input::PostInt('size');
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

        $model = new AdminOssModel();
        $item = $model->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '项目不存在');
        }

        $item->delete();
        Ret::Success(0, [], '删除成功');
    }
}