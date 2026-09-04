<?php

namespace app\admin\controller;

use app\admin\model\AdminCertWebsiteModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;
use Input;
use Ret;

class CertWebsite extends CommonController
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
        $type = input('get.type', 'web');
        $page = input('get.page', 1, 'intval');
        $limit = 15;

        $model = new AdminCertWebsiteModel();
        $total = $model->where('type', $type)->count();
        $totalPages = max(1, (int)ceil($total / $limit));

        $list = $model->where('type', $type)->order('id', 'desc')->page($page, $limit)->select();

        $items = [];
        foreach ($list as $item) {
            $items[] = [
                'id' => $item['id'],
                'cert_name' => $item['cert_name'],
                'website' => $item['website'],
                'type' => $item['type'],
                'api' => $item['api'],
                'key' => $item['key'],
                'status' => $item['status'],
            ];
        }

        $pagination = Layout::pagination($page, $totalPages, '/admin/cert_website', ['type' => $type]);

        return $this->renderPage('cert_website/index', [
            'list' => $items,
            'pagination' => $pagination,
            'currentType' => $type,
        ], '证书站点', 'cert', 'cert_website');
    }

    private function create()
    {
        $cert_name = request()->put('cert_name');
        $website = request()->put('website', '');
        $type = request()->put('type', 'web');
        $api = request()->put('api', '');
        $key = request()->put('key', '');
        $status = intval(request()->put('status', '1')) ?: 1;

        if (empty($cert_name)) {
            Ret::Fail(400, null, '证书名称不能为空');
        }

        $model = AdminCertWebsiteModel::create([
            'cert_name' => $cert_name,
            'website' => $website,
            'type' => $type,
            'api' => $api,
            'key' => $key,
            'status' => $status,
        ]);

        Ret::Success(0, ['id' => $model['id']], '创建成功');
    }

    private function update()
    {
        $id = Input::PostInt('id');
        $model = new AdminCertWebsiteModel();
        $item = $model->findOrEmpty($id);

        if ($item->isEmpty()) {
            Ret::Fail(404, null, '记录不存在');
        }

        $data = [];
        if (request()->has('cert_name', 'post')) {
            $data['cert_name'] = Input::Post('cert_name');
        }
        if (request()->has('website', 'post')) {
            $data['website'] = Input::Post('website', false);
        }
        if (request()->has('type', 'post')) {
            $data['type'] = Input::Post('type', false);
        }
        if (request()->has('api', 'post')) {
            $data['api'] = Input::Post('api', false);
        }
        if (request()->has('key', 'post')) {
            $data['key'] = Input::Post('key', false);
        }
        if (request()->has('status', 'post')) {
            $data['status'] = Input::PostInt('status');
        }

        $item->save($data);
        Ret::Success(0, [], '更新成功');
    }

    private function delete()
    {
        $id = intval(request()->delete('id'));
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminCertWebsiteModel();
        $item = $model->findOrEmpty($id);

        if ($item->isEmpty()) {
            Ret::Fail(404, null, '记录不存在');
        }

        $item->delete();
        Ret::Success(0, [], '删除成功');
    }

    public function toggleStatus()
    {
        $id = Input::PostInt('id');
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminCertWebsiteModel();
        $item = $model->findOrEmpty($id);

        if ($item->isEmpty()) {
            Ret::Fail(404, null, '记录不存在');
        }

        $newStatus = $item['status'] == 1 ? 0 : 1;
        $item->save(['status' => $newStatus]);

        $statusText = $newStatus == 1 ? '启用' : '禁用';
        Ret::Success(0, ['status' => $newStatus], "状态已切换为{$statusText}");
    }

    public function batchDelete()
    {
        $ids = request()->post('ids');
        if (empty($ids)) {
            Ret::Fail(400, null, '缺少参数[ids]');
        }

        $ids = json_decode($ids, true);
        if (!is_array($ids) || empty($ids)) {
            Ret::Fail(400, null, '参数格式错误');
        }

        $count = 0;
        $model = new AdminCertWebsiteModel();
        foreach ($ids as $id) {
            $id = intval($id);
            if ($id) {
                $item = $model->findOrEmpty($id);
                if (!$item->isEmpty()) {
                    $item->delete();
                    $count++;
                }
            }
        }

        Ret::Success(0, ['count' => $count], "已删除 {$count} 条记录");
    }
}