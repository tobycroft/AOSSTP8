<?php

namespace app\admin\controller;

use app\admin\model\AdminSmsModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;
use Input;
use Ret;

class Sms extends CommonController
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

        $model = new AdminSmsModel();
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
                'token' => $item['token'],
                'token_short' => mb_strlen($item['token']) > 16 ? mb_substr($item['token'], 0, 16) . '...' : $item['token'],
                'sms_type' => $item['sms_type'],
                'sms_tag' => $item['sms_tag'],
                'sms_limit' => $item['sms_limit'],
                'status' => $item['status'],
            ];
        }

        $pagination = Layout::pagination((int)$list['current_page'], max(1, (int)ceil($list['total'] / $limit)), '/admin/sms', ['search' => $search], $limit);

        return $this->renderPage('sms/index', [
            'list' => $items,
            'search' => $search,
            'pagination' => $pagination,
        ], '短信项目', 'sms', 'sms');
    }

    private function create()
    {
        $name = Input::Put('name');
        if (empty($name)) {
            Ret::Fail(400, null, '项目名称不能为空');
        }
        $token = Input::Put('token');
        if (empty($token)) {
            Ret::Fail(400, null, 'Token不能为空');
        }

        $data = [
            'name' => $name,
            'token' => $token,
            'code' => Input::Put('code', ''),
            'sms_type' => Input::Put('sms_type', 'none'),
            'sms_tag' => Input::Put('sms_tag', 'default'),
            'sms_limit' => Input::PutInt('sms_limit', 20),
            'status' => Input::PutInt('status', 1),
        ];

        AdminSmsModel::create($data);
        Ret::Success(0, [], '创建成功');
    }

    private function update()
    {
        $id = Input::PostInt('id');
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminSmsModel();
        $item = $model->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '项目不存在');
        }

        $data = [];
        if (request()->has('name', 'post')) $data['name'] = Input::Post('name');
        if (request()->has('token', 'post')) $data['token'] = Input::Post('token');
        if (request()->has('code', 'post')) $data['code'] = Input::Post('code');
        if (request()->has('sms_type', 'post')) $data['sms_type'] = Input::Post('sms_type');
        if (request()->has('sms_tag', 'post')) $data['sms_tag'] = Input::Post('sms_tag');
        if (request()->has('sms_limit', 'post')) $data['sms_limit'] = Input::PostInt('sms_limit');
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

        $model = new AdminSmsModel();
        $item = $model->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '项目不存在');
        }

        $item->delete();
        Ret::Success(0, [], '删除成功');
    }
}