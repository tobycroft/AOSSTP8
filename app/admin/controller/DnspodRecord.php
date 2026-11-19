<?php

namespace app\admin\controller;

use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;
use Dnspod\Dnspod as DnspodClient;
use Exception;
use Ret;
use think\facade\Db;

class DnspodRecord extends CommonController
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
                return $this->remove();
        }
        Ret::Fail(405, null, '不支持的请求方法');
    }

    private function page()
    {
        $page = input('get.page', 1, 'intval');
        $limit = 20;
        $search = input('get.search', '');
        $domainId = input('get.domain_id', 0, 'intval');
        $type = input('get.type', '');

        $query = Db::name('ao_dnspod_record')
            ->alias('r')
            ->leftJoin('ao_dnspod n', 'r.dnspod_id = n.id')
            ->field('r.*, n.name as config_name')
            ->order('r.domain_id', 'asc')
            ->order('r.record_id', 'asc');

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('r.name', 'like', '%' . $search . '%');
                $q->whereOr('r.value', 'like', '%' . $search . '%');
                $q->whereOr('r.domain_name', 'like', '%' . $search . '%');
            });
        }
        if ($domainId > 0) {
            $query->where('r.domain_id', $domainId);
        }
        if ($type !== '') {
            $query->where('r.type', $type);
        }

        $list = $query->paginate($limit, false, ['page' => $page])->toArray();

        $items = [];
        foreach ($list['data'] as $item) {
            $items[] = [
                'id' => $item['record_id'],
                'record_id' => $item['record_id'],
                'dnspod_id' => $item['dnspod_id'],
                'config_name' => $item['config_name'] ?? '-',
                'domain_id' => $item['domain_id'],
                'domain_name' => $item['domain_name'],
                'name' => $item['name'],
                'type' => $item['type'],
                'value' => $item['value'],
                'line' => $item['line'],
                'ttl' => $item['ttl'],
                'enabled' => $item['enabled'],
                'weight' => $item['weight'],
                'remark' => $item['remark'],
                'date' => $item['date'],
            ];
        }

        $queryParams = ['search' => $search, 'domain_id' => $domainId, 'type' => $type];
        $pagination = Layout::pagination((int)$list['current_page'], max(1, (int)ceil($list['total'] / $limit)), '/admin/dnspod_record', $queryParams, $limit);

        $total = (int)$list['total'];
        $lastSync = Db::name('ao_dnspod_record')->max('date');

        $domains = Db::name('ao_dnspod_domain')
            ->field('domain_id, name')
            ->order('name', 'asc')
            ->select()
            ->toArray();

        $types = Db::name('ao_dnspod_record')
            ->field('type')
            ->where('type', '<>', '')
            ->group('type')
            ->column('type');

        return $this->renderPage('dnspod_record/index', [
            'list' => $items,
            'search' => $search,
            'domainId' => $domainId,
            'type' => $type,
            'domains' => $domains,
            'types' => $types,
            'pagination' => $pagination,
            'total' => $total,
            'lastSync' => $lastSync ?: '-',
        ], '解析记录', 'dnspod', 'dnspod_record');
    }

    /**
     * 根据域名 did 获取 DNSPod 配置（api_id, api_token）
     */
    private function getConfigByDomainId(int $domainId): array
    {
        $domain = Db::name('ao_dnspod_domain')
            ->where('domain_id', $domainId)
            ->find();
        if (empty($domain)) {
            Ret::Fail(404, null, '域名不存在');
        }

        $config = Db::name('ao_dnspod')
            ->where('id', (int)$domain['dnspod_id'])
            ->where('status', 1)
            ->find();
        if (empty($config)) {
            Ret::Fail(400, null, '该域名对应的 DNSPod 配置不可用');
        }

        return $config;
    }

    /**
     * 创建解析记录
     * PUT /admin/dnspod_record
     */
    private function create()
    {
        $domainId = input('put.domain_id', 0, 'intval');
        if ($domainId <= 0) {
            Ret::Fail(400, null, '缺少参数[domain_id]');
        }
        $name = input('put.name', '');
        if ($name === '') {
            Ret::Fail(400, null, '主机记录不能为空');
        }
        $type = input('put.type', '');
        if ($type === '') {
            Ret::Fail(400, null, '记录类型不能为空');
        }
        $value = input('put.value', '');
        if ($value === '') {
            Ret::Fail(400, null, '记录值不能为空');
        }
        $line = input('put.line', '默认');
        $ttl = input('put.ttl', 600, 'intval');

        $config = $this->getConfigByDomainId($domainId);

        try {
            $client = new DnspodClient((int)$config['api_id'], (string)$config['api_token']);
            $record = $client->recordCreate($domainId, $name, $type, $value, $line, $ttl);
        } catch (Exception $e) {
            Ret::Fail(500, null, '创建失败: ' . $e->getMessage());
        }

        $recordId = (int)($record['id'] ?? 0);
        if ($recordId <= 0) {
            Ret::Fail(500, null, '创建成功但未获取到记录 ID');
        }

        $now = date('Y-m-d H:i:s');
        $domainInfo = Db::name('ao_dnspod_domain')->where('domain_id', $domainId)->find();
        Db::name('ao_dnspod_record')->insert([
            'domain_id' => $domainId,
            'record_id' => $recordId,
            'dnspod_id' => (int)$config['id'],
            'domain_name' => $domainInfo['name'] ?? '',
            'name' => $name,
            'type' => $type,
            'value' => $value,
            'line' => $line,
            'ttl' => $ttl,
            'enabled' => '1',
            'weight' => 0,
            'remark' => '',
            'date' => $now,
        ]);

        Ret::Success(0, ['record_id' => $recordId], '创建成功');
    }

    /**
     * 修改解析记录
     * POST /admin/dnspod_record
     */
    private function update()
    {
        $domainId = input('post.domain_id', 0, 'intval');
        $recordId = input('post.record_id', 0, 'intval');
        if ($domainId <= 0) {
            Ret::Fail(400, null, '缺少参数[domain_id]');
        }
        if ($recordId <= 0) {
            Ret::Fail(400, null, '缺少参数[record_id]');
        }
        $name = input('post.name', '');
        $type = input('post.type', '');
        $value = input('post.value', '');
        $line = input('post.line', '默认');
        $ttl = input('post.ttl', 600, 'intval');

        if ($name === '' || $type === '' || $value === '') {
            Ret::Fail(400, null, '主机记录、记录类型、记录值不能为空');
        }

        $config = $this->getConfigByDomainId($domainId);

        try {
            $client = new DnspodClient((int)$config['api_id'], (string)$config['api_token']);
            $client->recordModify($domainId, $recordId, $name, $type, $value, $line, $ttl);
        } catch (Exception $e) {
            Ret::Fail(500, null, '修改失败: ' . $e->getMessage());
        }

        Db::name('ao_dnspod_record')
            ->where('domain_id', $domainId)
            ->where('record_id', $recordId)
            ->update([
                'name' => $name,
                'type' => $type,
                'value' => $value,
                'line' => $line,
                'ttl' => $ttl,
                'date' => date('Y-m-d H:i:s'),
            ]);

        Ret::Success(0, [], '修改成功');
    }

    /**
     * 删除解析记录
     * DELETE /admin/dnspod_record
     */
    private function remove()
    {
        $domainId = input('param.domain_id', 0, 'intval');
        $recordId = input('param.record_id', 0, 'intval');
        if ($domainId <= 0 || $recordId <= 0) {
            Ret::Fail(400, null, '缺少参数[domain_id]或[record_id]');
        }

        $config = $this->getConfigByDomainId($domainId);

        try {
            $client = new DnspodClient((int)$config['api_id'], (string)$config['api_token']);
            $client->recordRemove($domainId, $recordId);
        } catch (Exception $e) {
            Ret::Fail(500, null, '删除失败: ' . $e->getMessage());
        }

        Db::name('ao_dnspod_record')
            ->where('domain_id', $domainId)
            ->where('record_id', $recordId)
            ->delete();

        Ret::Success(0, [], '删除成功');
    }

    /**
     * 修改记录状态（启用/禁用）
     * POST /admin/dnspod_record/status
     */
    public function status()
    {
        $domainId = input('post.domain_id', 0, 'intval');
        $recordId = input('post.record_id', 0, 'intval');
        $status = input('post.status', '');
        if ($domainId <= 0 || $recordId <= 0) {
            Ret::Fail(400, null, '缺少参数[domain_id]或[record_id]');
        }
        if (!in_array($status, ['enable', 'disable'], true)) {
            Ret::Fail(400, null, '参数[status]只能为 enable 或 disable');
        }

        $config = $this->getConfigByDomainId($domainId);

        try {
            $client = new DnspodClient((int)$config['api_id'], (string)$config['api_token']);
            $client->recordStatus($domainId, $recordId, $status);
        } catch (Exception $e) {
            Ret::Fail(500, null, '状态修改失败: ' . $e->getMessage());
        }

        Db::name('ao_dnspod_record')
            ->where('domain_id', $domainId)
            ->where('record_id', $recordId)
            ->update([
                'enabled' => $status === 'enable' ? '1' : '0',
                'date' => date('Y-m-d H:i:s'),
            ]);

        Ret::Success(0, [], $status === 'enable' ? '已启用' : '已禁用');
    }
}