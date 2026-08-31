<?php

namespace app\admin\controller;

use app\admin\model\AdminSmsWlwxModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;
use Input;
use Ret;

class SmsWlwx extends CommonController
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

        $model = new AdminSmsWlwxModel();
        $list = $model->api_list($page, $limit);

        $items = [];
        foreach ($list['data'] as $item) {
            $items[] = [
                'id' => $item['id'],
                'tag' => $item['tag'],
                'cust_code' => $item['cust_code'],
                'password' => '****',
                'template' => $item['template'],
            ];
        }

        $pagination = Layout::pagination((int)$list['current_page'], max(1, (int)ceil($list['total'] / $limit)), '/admin/sms_wlwx');

        return $this->renderPage('sms_wlwx/index', [
            'list' => $items,
            'pagination' => $pagination,
        ], '网路万象', 'sms', 'sms_wlwx');
    }

    private function create()
    {
        $tag = Input::Put('tag');
        if (empty($tag)) {
            Ret::Fail(400, null, '标签不能为空');
        }

        $data = [
            'tag' => $tag,
            'cust_code' => Input::Put('cust_code', ''),
            'password' => Input::Put('password', ''),
            'template' => Input::Put('template', ''),
        ];

        AdminSmsWlwxModel::create($data);
        Ret::Success(0, [], '创建成功');
    }

    private function update()
    {
        $id = Input::PostInt('id');
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminSmsWlwxModel();
        $item = $model->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '配置不存在');
        }

        $data = [];
        if (request()->has('tag', 'post')) $data['tag'] = Input::Post('tag');
        if (request()->has('cust_code', 'post')) $data['cust_code'] = Input::Post('cust_code');
        if (request()->has('password', 'post')) $data['password'] = Input::Post('password');
        if (request()->has('template', 'post')) $data['template'] = Input::Post('template');

        $item->save($data);
        Ret::Success(0, [], '更新成功');
    }

    private function delete()
    {
        $id = intval(request()->delete('id'));
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminSmsWlwxModel();
        $item = $model->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '配置不存在');
        }

        $item->delete();
        Ret::Success(0, [], '删除成功');
    }
}