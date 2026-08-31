<?php

namespace app\admin\controller;

use app\admin\model\AdminSmsAliyunModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;
use Input;
use Ret;

class SmsAliyun extends CommonController
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

        $model = new AdminSmsAliyunModel();
        $query = $model->order('id', 'desc');
        if (!empty($search)) {
            $query->where('tag', 'like', '%' . $search . '%');
        }
        $list = $query->paginate($limit, false, ['page' => $page])->toArray();

        $items = [];
        foreach ($list['data'] as $item) {
            $items[] = [
                'id' => $item['id'],
                'tag' => $item['tag'],
                'accessid' => $item['accessid'],
                'accesskey' => '****',
                'sign' => $item['sign'],
                'tpcode' => $item['tpcode'],
            ];
        }

        $pagination = Layout::pagination((int)$list['current_page'], max(1, (int)ceil($list['total'] / $limit)), '/admin/sms_aliyun', ['search' => $search], $limit);

        return $this->renderPage('sms_aliyun/index', [
            'list' => $items,
            'search' => $search,
            'pagination' => $pagination,
        ], '阿里云短信', 'sms', 'sms_aliyun');
    }

    private function create()
    {
        $tag = Input::Put('tag');
        if (empty($tag)) {
            Ret::Fail(400, null, '标签不能为空');
        }

        $data = [
            'tag' => $tag,
            'accessid' => Input::Put('accessid', ''),
            'accesskey' => Input::Put('accesskey', ''),
            'sign' => Input::Put('sign', ''),
            'tpcode' => Input::Put('tpcode', ''),
        ];

        AdminSmsAliyunModel::create($data);
        Ret::Success(0, [], '创建成功');
    }

    private function update()
    {
        $id = Input::PostInt('id');
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminSmsAliyunModel();
        $item = $model->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '配置不存在');
        }

        $data = [];
        if (request()->has('tag', 'post')) $data['tag'] = Input::Post('tag');
        if (request()->has('accessid', 'post')) $data['accessid'] = Input::Post('accessid');
        if (request()->has('accesskey', 'post')) $data['accesskey'] = Input::Post('accesskey');
        if (request()->has('sign', 'post')) $data['sign'] = Input::Post('sign');
        if (request()->has('tpcode', 'post')) $data['tpcode'] = Input::Post('tpcode');

        $item->save($data);
        Ret::Success(0, [], '更新成功');
    }

    private function delete()
    {
        $id = intval(request()->delete('id'));
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminSmsAliyunModel();
        $item = $model->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '配置不存在');
        }

        $item->delete();
        Ret::Success(0, [], '删除成功');
    }
}