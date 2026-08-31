<?php

namespace app\admin\controller;

use app\admin\model\AdminSmsBlacklistModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;
use Input;
use Ret;

class SmsBlacklist extends CommonController
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
                $id = Input::PostInt('id');
                if ($id) {
                    return $this->update($id);
                }
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

        $model = new AdminSmsBlacklistModel();
        $list = $model->order('id', 'desc')->paginate($limit, false, ['page' => $page])->toArray();

        $items = [];
        foreach ($list['data'] as $item) {
            $items[] = [
                'id' => $item['id'],
                'name' => $item['name'] ?? '',
                'phone' => $item['phone'] ?? '',
                'change_date' => $item['change_date'] ?? '',
                'date' => $item['date'] ?? '',
            ];
        }

        $pagination = Layout::pagination((int)$list['current_page'], max(1, (int)ceil($list['total'] / $limit)), '/admin/sms_blacklist');

        return $this->renderPage('sms_blacklist/index', [
            'list' => $items,
            'pagination' => $pagination,
        ], '短信黑名单', 'sms', 'sms_blacklist');
    }

    private function create()
    {
        $name = Input::Post('name');
        $phone = Input::Post('phone');

        if (empty($name)) {
            Ret::Fail(400, null, '名称不能为空');
        }
        if (empty($phone)) {
            Ret::Fail(400, null, '手机号不能为空');
        }

        $data = [
            'name' => $name,
            'phone' => $phone,
        ];

        AdminSmsBlacklistModel::create($data);
        Ret::Success(0, [], '创建成功');
    }

    private function update($id)
    {
        $model = new AdminSmsBlacklistModel();
        $item = $model->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '记录不存在');
        }

        $data = [];
        if (request()->has('name', 'post')) $data['name'] = Input::Post('name');
        if (request()->has('phone', 'post')) $data['phone'] = Input::Post('phone');

        $item->save($data);
        Ret::Success(0, [], '更新成功');
    }

    private function delete()
    {
        $id = intval(request()->delete('id'));
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminSmsBlacklistModel();
        $item = $model->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '记录不存在');
        }

        $item->delete();
        Ret::Success(0, [], '删除成功');
    }
}