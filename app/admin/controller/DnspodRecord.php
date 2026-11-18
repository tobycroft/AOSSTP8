<?php

namespace app\admin\controller;

use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;
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
        if ($method === 'GET') {
            return $this->page();
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
            ->order('r.id', 'asc');

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
                'id' => $item['id'],
                'dnspod_id' => $item['dnspod_id'],
                'config_name' => $item['config_name'] ?? '-',
                'domain_id' => $item['domain_id'],
                'domain_name' => $item['domain_name'],
                'record_id' => $item['record_id'],
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
}