<?php

namespace app\admin\controller;

use app\admin\model\AdminSmsLcModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;
use Input;
use Ret;

class SmsLc extends CommonController
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

        $model = new AdminSmsLcModel();
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
                'mch_id' => $item['mch_id'],
                'key' => '****',
                'sign' => $item['sign'],
                'tpcode' => $item['tpcode'],
                'reverse_addr' => $item['reverse_addr'],
            ];
        }

        $pagination = Layout::pagination((int)$list['current_page'], max(1, (int)ceil($list['total'] / $limit)), '/admin/sms_lc', ['search' => $search], $limit);

        return $this->renderPage('sms_lc/index', [
            'list' => $items,
            'search' => $search,
            'pagination' => $pagination,
        ], 'LC短信', 'sms', 'sms_lc');
    }

    private function create()
    {
        $tag = Input::Put('tag');
        if (empty($tag)) {
            Ret::Fail(400, null, '标签不能为空');
        }

        $data = [
            'tag' => $tag,
            'mch_id' => Input::Put('mch_id', ''),
            'key' => Input::Put('key', ''),
            'sign' => Input::Put('sign', ''),
            'tpcode' => Input::Put('tpcode', ''),
            'reverse_addr' => Input::Put('reverse_addr', ''),
            'discription' => Input::Put('discription', ''),
        ];

        AdminSmsLcModel::create($data);
        Ret::Success(0, [], '创建成功');
    }

    private function update()
    {
        $id = Input::PostInt('id');
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminSmsLcModel();
        $item = $model->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '配置不存在');
        }

        $data = [];
        if (request()->has('tag', 'post')) $data['tag'] = Input::Post('tag');
        if (request()->has('mch_id', 'post')) $data['mch_id'] = Input::Post('mch_id');
        if (request()->has('key', 'post')) $data['key'] = Input::Post('key');
        if (request()->has('sign', 'post')) $data['sign'] = Input::Post('sign');
        if (request()->has('tpcode', 'post')) $data['tpcode'] = Input::Post('tpcode');
        if (request()->has('reverse_addr', 'post')) $data['reverse_addr'] = Input::Post('reverse_addr');
        if (request()->has('discription', 'post')) $data['discription'] = Input::Post('discription');

        $item->save($data);
        Ret::Success(0, [], '更新成功');
    }

    private function delete()
    {
        $id = intval(request()->delete('id'));
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminSmsLcModel();
        $item = $model->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '配置不存在');
        }

        $item->delete();
        Ret::Success(0, [], '删除成功');
    }
}