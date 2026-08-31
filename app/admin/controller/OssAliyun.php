<?php

namespace app\admin\controller;

use app\admin\model\AdminOssAliyunModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;
use Input;
use Ret;

class OssAliyun extends CommonController
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

        $model = new AdminOssAliyunModel();
        $list = $model->api_list($page, $limit);

        $items = [];
        foreach ($list['data'] as $item) {
            $items[] = [
                'id' => $item['id'],
                'tag' => $item['tag'],
                'accesskey' => $item['accesskey'],
                'accesssecret' => $item['accesssecret'],
                'endpoint' => $item['endpoint'],
                'bucket' => $item['bucket'],
            ];
        }

        $pagination = Layout::pagination((int)$list['current_page'], max(1, (int)ceil($list['total'] / $limit)), '/admin/oss_aliyun');

        return $this->renderPage('oss_aliyun/index', [
            'list' => $items,
            'pagination' => $pagination,
        ], '阿里云OSS', 'storage', 'oss_aliyun');
    }

    private function create()
    {
        $accesskey = Input::Put('accesskey');
        if (empty($accesskey)) {
            Ret::Fail(400, null, 'AccessKey 不能为空');
        }

        $data = [
            'tag' => Input::Put('tag', 'default'),
            'accesskey' => $accesskey,
            'accesssecret' => Input::Put('accesssecret', ''),
            'endpoint' => Input::Put('endpoint', ''),
            'bucket' => Input::Put('bucket', ''),
        ];

        AdminOssAliyunModel::create($data);
        Ret::Success(0, [], '创建成功');
    }

    private function update()
    {
        $id = Input::PostInt('id');
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminOssAliyunModel();
        $item = $model->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '配置不存在');
        }

        $data = [];
        if (request()->has('tag', 'post')) $data['tag'] = Input::Post('tag');
        if (request()->has('accesskey', 'post')) $data['accesskey'] = Input::Post('accesskey');
        if (request()->has('accesssecret', 'post')) $data['accesssecret'] = Input::Post('accesssecret');
        if (request()->has('endpoint', 'post')) $data['endpoint'] = Input::Post('endpoint');
        if (request()->has('bucket', 'post')) $data['bucket'] = Input::Post('bucket');

        $item->save($data);
        Ret::Success(0, [], '更新成功');
    }

    private function delete()
    {
        $id = intval(request()->delete('id'));
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminOssAliyunModel();
        $item = $model->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '配置不存在');
        }

        $item->delete();
        Ret::Success(0, [], '删除成功');
    }
}