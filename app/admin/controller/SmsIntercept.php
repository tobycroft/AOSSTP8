<?php

namespace app\admin\controller;

use app\admin\model\AdminSmsInterceptModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;
use Input;
use Ret;

class SmsIntercept extends CommonController
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
        $search = input('get.search', '');

        $model = new AdminSmsInterceptModel();
        $query = $model->order('id', 'desc');
        if (!empty($search)) {
            $query->where('phone', 'like', '%' . $search . '%');
        }
        $list = $query->paginate($limit, false, ['page' => $page])->toArray();

        $items = [];
        foreach ($list['data'] as $item) {
            $items[] = [
                'id' => $item['id'],
                'phone' => $item['phone'] ?? '',
                'num' => $item['num'] ?? '',
                'change_date' => $item['change_date'] ?? '',
                'date' => $item['date'] ?? '',
            ];
        }

        $pagination = Layout::pagination((int)$list['current_page'], max(1, (int)ceil($list['total'] / $limit)), '/admin/sms_intercept', ['search' => $search], $limit);

        return $this->renderPage('sms_intercept/index', [
            'list' => $items,
            'search' => $search,
            'pagination' => $pagination,
        ], '拦截规则', 'sms', 'sms_intercept');
    }

    private function create()
    {
        $phone = Input::Post('phone');
        $num = Input::Post('num');

        if (empty($phone)) {
            Ret::Fail(400, null, '手机号不能为空');
        }
        if (empty($num)) {
            Ret::Fail(400, null, '次数不能为空');
        }

        $data = [
            'phone' => $phone,
            'num' => $num,
        ];

        AdminSmsInterceptModel::create($data);
        Ret::Success(0, [], '创建成功');
    }

    private function update($id)
    {
        $model = new AdminSmsInterceptModel();
        $item = $model->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '记录不存在');
        }

        $data = [];
        if (request()->has('phone', 'post')) $data['phone'] = Input::Post('phone');
        if (request()->has('num', 'post')) $data['num'] = Input::Post('num');

        $item->save($data);
        Ret::Success(0, [], '更新成功');
    }

    private function delete()
    {
        $id = intval(request()->delete('id'));
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminSmsInterceptModel();
        $item = $model->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '记录不存在');
        }

        $item->delete();
        Ret::Success(0, [], '删除成功');
    }
}