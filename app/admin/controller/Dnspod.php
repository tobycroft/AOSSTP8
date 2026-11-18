<?php

namespace app\admin\controller;

use app\admin\model\AdminDnspodModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;
use Dnspod\Dnspod as DnspodClient;
use Exception;
use Input;
use Ret;

class Dnspod extends CommonController
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

        $model = new AdminDnspodModel();
        $query = $model->order('id', 'desc');
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%');
                $q->whereOr('api_id', 'like', '%' . $search . '%');
            });
        }
        $list = $query->paginate($limit, false, ['page' => $page])->toArray();

        $items = [];
        foreach ($list['data'] as $item) {
            $token = (string)($item['api_token'] ?? '');
            $items[] = [
                'id' => $item['id'],
                'name' => $item['name'],
                'api_id' => $item['api_id'],
                'api_token' => $token,
                'api_token_short' => mb_strlen($token) > 12 ? mb_substr($token, 0, 6) . '...' . mb_substr($token, -4) : $token,
                'status' => $item['status'],
            ];
        }

        $pagination = Layout::pagination((int)$list['current_page'], max(1, (int)ceil($list['total'] / $limit)), '/admin/dnspod', ['search' => $search], $limit);

        return $this->renderPage('dnspod/index', [
            'list' => $items,
            'search' => $search,
            'pagination' => $pagination,
        ], 'DNSPod 管理', 'dnspod', 'dnspod');
    }

    private function create()
    {
        $name = Input::Put('name');
        if (empty($name)) {
            Ret::Fail(400, null, '配置名称不能为空');
        }
        $apiId = Input::PutInt('api_id', 0);
        if ($apiId <= 0) {
            Ret::Fail(400, null, 'API ID 不能为空');
        }
        $apiToken = Input::Put('api_token', '');
        if (empty($apiToken)) {
            Ret::Fail(400, null, 'API Token 不能为空');
        }

        AdminDnspodModel::create([
            'name' => $name,
            'api_id' => $apiId,
            'api_token' => $apiToken,
            'status' => Input::PutInt('status', 1),
        ]);
        Ret::Success(0, [], '创建成功');
    }

    private function update()
    {
        $id = Input::PostInt('id');
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminDnspodModel();
        $item = $model->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, 'DNSPod 配置不存在');
        }

        $data = [];
        if (request()->has('name', 'post')) $data['name'] = Input::Post('name');
        if (request()->has('api_id', 'post')) $data['api_id'] = Input::PostInt('api_id');
        if (request()->has('api_token', 'post')) $data['api_token'] = Input::Post('api_token');
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

        $model = new AdminDnspodModel();
        $item = $model->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, 'DNSPod 配置不存在');
        }

        $item->delete();
        Ret::Success(0, [], '删除成功');
    }

    /**
     * 在线测试：调用 Info.Version 验证 API ID / Token 是否可用
     */
    public function test()
    {
        $id = Input::PostInt('id');
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $config = AdminDnspodModel::getConfig($id);
        if (empty($config)) {
            Ret::Fail(400, null, '配置不存在或已被禁用');
        }

        try {
            $client = new DnspodClient($config['api_id'], $config['api_token']);
            $data = $client->test();
        } catch (Exception $e) {
            Ret::Fail(500, null, '测试失败: ' . $e->getMessage());
        }

        Ret::Success(0, [
            'status' => $data['status'] ?? [],
        ], '测试成功，API ID / Token 有效');
    }

    /**
     * 获取域名列表（在线测试用）
     */
    public function domains()
    {
        $id = Input::PostInt('id');
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $config = AdminDnspodModel::getConfig($id);
        if (empty($config)) {
            Ret::Fail(400, null, '配置不存在或已被禁用');
        }

        try {
            $client = new DnspodClient($config['api_id'], $config['api_token']);
            $domains = $client->domainList();
        } catch (Exception $e) {
            Ret::Fail(500, null, '获取域名列表失败: ' . $e->getMessage());
        }

        $list = [];
        foreach ($domains as $domain) {
            $list[] = [
                'id' => (int)($domain['id'] ?? 0),
                'name' => (string)($domain['name'] ?? ''),
                'punycode' => (string)($domain['punycode'] ?? ''),
                'status' => (string)($domain['status'] ?? ''),
                'records' => (int)($domain['records'] ?? 0),
                'grade' => (string)($domain['grade'] ?? ''),
            ];
        }

        Ret::Success(0, ['domains' => $list], '获取成功，共 ' . count($list) . ' 个域名');
    }

    /**
     * 获取解析记录列表（在线测试用）
     */
    public function records()
    {
        $id = Input::PostInt('id');
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }
        $domainId = Input::PostInt('domain_id');
        if (!$domainId) {
            Ret::Fail(400, null, '缺少参数[domain_id]');
        }

        $config = AdminDnspodModel::getConfig($id);
        if (empty($config)) {
            Ret::Fail(400, null, '配置不存在或已被禁用');
        }

        $params = [];
        if (request()->has('sub_domain', 'post')) {
            $params['sub_domain'] = Input::Post('sub_domain', false);
        }
        if (request()->has('record_type', 'post')) {
            $params['record_type'] = Input::Post('record_type', false);
        }

        try {
            $client = new DnspodClient($config['api_id'], $config['api_token']);
            $records = $client->recordList($domainId, $params);
        } catch (Exception $e) {
            Ret::Fail(500, null, '获取记录列表失败: ' . $e->getMessage());
        }

        $list = [];
        foreach ($records as $record) {
            $list[] = [
                'id' => (int)($record['id'] ?? 0),
                'name' => (string)($record['name'] ?? ''),
                'type' => (string)($record['type'] ?? ''),
                'value' => (string)($record['value'] ?? ''),
                'line' => (string)($record['line'] ?? ''),
                'ttl' => (int)($record['ttl'] ?? 0),
                'enabled' => (string)($record['enabled'] ?? '1'),
                'weight' => (int)($record['weight'] ?? 0),
                'remark' => (string)($record['remark'] ?? ''),
                'updated_on' => (string)($record['updated_on'] ?? ''),
            ];
        }

        Ret::Success(0, ['records' => $list], '获取成功，共 ' . count($list) . ' 条记录');
    }
}