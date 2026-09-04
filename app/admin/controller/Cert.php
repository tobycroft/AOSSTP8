<?php

namespace app\admin\controller;

use app\admin\model\AdminCertModel;
use app\admin\model\AdminCertWebsiteModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use app\v1\cert\action\SiteAction;
use BaseController\CommonController;
use Input;
use Ret;
use think\Exception;

class Cert extends CommonController
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

        $model = new AdminCertModel();
        $list = $model->order('id', 'desc')->paginate($limit, false, ['page' => $page]);

        $currentPage = (int)$list->currentPage();
        $total = $list->total();
        $totalPages = max(1, (int)ceil($total / $limit));

        $items = [];
        foreach ($list as $item) {
            $items[] = [
                'id' => $item['id'],
                'appname' => $item['appname'],
                'appkey' => $item['appkey'],
                'bt_api' => $item['bt_api'],
                'bt_key' => $item['bt_key'],
                'status' => $item['status'],
            ];
        }

        $pagination = Layout::pagination($currentPage, $totalPages, '/admin/cert');

        return $this->renderPage('cert/index', [
            'list' => $items,
            'pagination' => $pagination,
        ], '证书项目', 'cert', 'cert');
    }

    private function create()
    {
        $appname = request()->put('appname');
        $appkey = request()->put('appkey', '');
        $bt_api = request()->put('bt_api', '');
        $bt_key = request()->put('bt_key', '');
        $status = intval(request()->put('status', '1')) ?: 1;

        if (empty($appname)) {
            Ret::Fail(400, null, 'AppName 不能为空');
        }

        $cert = AdminCertModel::create([
            'appname' => $appname,
            'appkey' => $appkey,
            'bt_api' => $bt_api,
            'bt_key' => $bt_key,
            'status' => $status,
        ]);

        Ret::Success(0, ['id' => $cert['id']], '创建成功');
    }

    private function update()
    {
        $id = Input::PostInt('id');
        $model = new AdminCertModel();
        $cert = $model->findOrEmpty($id);

        if ($cert->isEmpty()) {
            Ret::Fail(404, null, '项目不存在');
        }

        $data = [];
        if (request()->has('appname', 'post')) {
            $data['appname'] = Input::Post('appname');
        }
        if (request()->has('appkey', 'post')) {
            $data['appkey'] = Input::Post('appkey', false);
        }
        if (request()->has('bt_api', 'post')) {
            $data['bt_api'] = Input::Post('bt_api', false);
        }
        if (request()->has('bt_key', 'post')) {
            $data['bt_key'] = Input::Post('bt_key', false);
        }
        if (request()->has('status', 'post')) {
            $data['status'] = Input::PostInt('status');
        }

        $cert->save($data);
        Ret::Success(0, [], '更新成功');
    }

    private function delete()
    {
        $id = intval(request()->delete('id'));
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminCertModel();
        $cert = $model->findOrEmpty($id);

        if ($cert->isEmpty()) {
            Ret::Fail(404, null, '项目不存在');
        }

        $cert->delete();
        Ret::Success(0, [], '删除成功');
    }

    public function updateSite()
    {
        $id = Input::PostInt('id');
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminCertModel();
        $cert = $model->findOrEmpty($id);
        if ($cert->isEmpty()) {
            Ret::Fail(404, null, '项目不存在');
        }

        try {
            $domains = SiteAction::getDomainList($cert['bt_api'], $cert['bt_key']);
        } catch (Exception $e) {
            Ret::Fail(500, null, $e->getMessage());
        }
if (empty($domains)) {
            Ret::Success(0, ['added' => 0, 'skipped' => 0], '未获取到域名');
        }

        $existingWebsites = AdminCertWebsiteModel::where('type', 'web')->column('website');
        $added = 0;
        $skipped = 0;

        foreach ($domains as $domain) {
            if (empty($domain)) {
                continue;
            }
            if (in_array($domain, $existingWebsites)) {
                $skipped++;
                continue;
            }
            AdminCertWebsiteModel::create([
                'website' => $domain,
                'type' => 'web',
                'api' => $cert['bt_api'],
                'key' => $cert['bt_key'],
                'cert_name' => $cert['appname'],
                'status' => 0,
            ]);
            $existingWebsites[] = $domain;
            $added++;
        }

        Ret::Success(0, ['added' => $added, 'skipped' => $skipped], "新增 {$added} 个站点，跳过 {$skipped} 个已存在站点");
    }
}