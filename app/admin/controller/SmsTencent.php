<?php

namespace app\admin\controller;

use app\admin\model\AdminSmsTencentModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;
use Input;
use Ret;

class SmsTencent extends CommonController
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

        $model = new AdminSmsTencentModel();
        $list = $model->api_list($page, $limit);

        $items = [];
        foreach ($list['data'] as $item) {
            $items[] = [
                'id' => $item['id'],
                'tag' => $item['tag'],
                'appid' => $item['appid'],
                'appkey' => '****',
                'sign' => $item['sign'],
                'tplid' => $item['tplid'],
            ];
        }

        $pagination = Layout::pagination((int)$list['current_page'], max(1, (int)ceil($list['total'] / $limit)), '/admin/sms_tencent');

        return $this->renderPage('sms_tencent/index', [
            'list' => $items,
            'pagination' => $pagination,
        ], '腾讯云短信', 'sms', 'sms_tencent');
    }

    private function create()
    {
        $tag = Input::Put('tag');
        if (empty($tag)) {
            Ret::Fail(400, null, '标签不能为空');
        }

        $data = [
            'tag' => $tag,
            'appid' => Input::Put('appid', ''),
            'appkey' => Input::Put('appkey', ''),
            'sign' => Input::Put('sign', ''),
            'tplid' => Input::Put('tplid', ''),
        ];

        AdminSmsTencentModel::create($data);
        Ret::Success(0, [], '创建成功');
    }

    private function update()
    {
        $id = Input::PostInt('id');
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminSmsTencentModel();
        $item = $model->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '配置不存在');
        }

        $data = [];
        if (request()->has('tag', 'post')) $data['tag'] = Input::Post('tag');
        if (request()->has('appid', 'post')) $data['appid'] = Input::Post('appid');
        if (request()->has('appkey', 'post')) $data['appkey'] = Input::Post('appkey');
        if (request()->has('sign', 'post')) $data['sign'] = Input::Post('sign');
        if (request()->has('tplid', 'post')) $data['tplid'] = Input::Post('tplid');

        $item->save($data);
        Ret::Success(0, [], '更新成功');
    }

    private function delete()
    {
        $id = intval(request()->delete('id'));
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminSmsTencentModel();
        $item = $model->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '配置不存在');
        }

        $item->delete();
        Ret::Success(0, [], '删除成功');
    }
}