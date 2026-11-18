<?php

namespace app\admin\controller;

use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;
use Ret;
use think\facade\Db;

class DnspodSubdomain extends CommonController
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

    /**
     * 实时搜索 JSON 接口：GET /admin/dnspod_subdomain/data
     */
    public function data()
    {
        $page = input('get.page', 1, 'intval');
        $limit = 20;
        $search = input('get.search', '');
        $domainId = input('get.domain_id', 0, 'intval');

        $query = Db::name('ao_dnspod_record')
            ->alias('r')
            ->leftJoin('ao_dnspod n', 'r.dnspod_id = n.id')
            ->field([
                'r.domain_id',
                'r.domain_name',
                'r.name as sub_domain',
                'n.name as config_name',
                'COUNT(*) as record_count',
                'GROUP_CONCAT(DISTINCT r.type ORDER BY r.type) as types',
                "MAX(CASE WHEN r.enabled='1' THEN 1 ELSE 0 END) as has_enabled",
                'MAX(r.date) as date',
            ])
            ->group('r.domain_id, r.domain_name, r.name, n.name')
            ->order('r.domain_name', 'asc');

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('r.domain_name', 'like', '%' . $search . '%');
                $q->whereOr('r.name', 'like', '%' . $search . '%');
                $q->whereOr('n.name', 'like', '%' . $search . '%');
            });
        }
        if ($domainId > 0) {
            $query->where('r.domain_id', $domainId);
        }

        $list = $query->paginate($limit, false, ['page' => $page])->toArray();

        $items = [];
        foreach ($list['data'] as $item) {
            $subDomain = $item['sub_domain'];
            $subFull = ($subDomain === '@') ? $item['domain_name'] : $subDomain . '.' . $item['domain_name'];

            $items[] = [
                'domain_id' => $item['domain_id'],
                'domain_name' => $item['domain_name'],
                'sub_domain' => $subDomain,
                'sub_full' => $subFull,
                'config_name' => $item['config_name'] ?? '-',
                'record_count' => (int)$item['record_count'],
                'types' => $item['types'] ? explode(',', $item['types']) : [],
                'has_enabled' => (int)$item['has_enabled'],
                'date' => $item['date'],
            ];
        }

        Ret::Success(0, [
            'list' => $items,
            'total' => (int)$list['total'],
            'current_page' => (int)$list['current_page'],
            'total_pages' => max(1, (int)ceil($list['total'] / $limit)),
        ]);
    }

    private function page()
    {
        $page = input('get.page', 1, 'intval');
        $limit = 20;
        $search = input('get.search', '');
        $domainId = input('get.domain_id', 0, 'intval');

        $query = Db::name('ao_dnspod_record')
            ->alias('r')
            ->leftJoin('ao_dnspod n', 'r.dnspod_id = n.id')
            ->field([
                'r.domain_id',
                'r.domain_name',
                'r.name as sub_domain',
                'n.name as config_name',
                'COUNT(*) as record_count',
                'GROUP_CONCAT(DISTINCT r.type ORDER BY r.type) as types',
                "MAX(CASE WHEN r.enabled='1' THEN 1 ELSE 0 END) as has_enabled",
                'MAX(r.date) as date',
            ])
            ->group('r.domain_id, r.domain_name, r.name, n.name')
            ->order('r.domain_name', 'asc');

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('r.domain_name', 'like', '%' . $search . '%');
                $q->whereOr('r.name', 'like', '%' . $search . '%');
                $q->whereOr('n.name', 'like', '%' . $search . '%');
            });
        }
        if ($domainId > 0) {
            $query->where('r.domain_id', $domainId);
        }

        $list = $query->paginate($limit, false, ['page' => $page])->toArray();

        $items = [];
        foreach ($list['data'] as $item) {
            $subDomain = $item['sub_domain'];
            $subFull = ($subDomain === '@') ? $item['domain_name'] : $subDomain . '.' . $item['domain_name'];
            $typeList = $item['types'] ? explode(',', $item['types']) : [];

            $items[] = [
                'domain_id' => $item['domain_id'],
                'domain_name' => $item['domain_name'],
                'sub_domain' => $subDomain,
                'sub_full' => $subFull,
                'config_name' => $item['config_name'] ?? '-',
                'record_count' => (int)$item['record_count'],
                'types' => $item['types'] ?? '',
                'type_list' => $typeList,
                'has_enabled' => (int)$item['has_enabled'],
                'date' => $item['date'],
            ];
        }

        $queryParams = ['search' => $search, 'domain_id' => $domainId];
        $pagination = Layout::pagination((int)$list['current_page'], max(1, (int)ceil($list['total'] / $limit)), '/admin/dnspod_subdomain', $queryParams, $limit);

        $total = (int)$list['total'];
        $lastSync = Db::name('ao_dnspod_record')->max('date');

        $domains = Db::name('ao_dnspod_domain')
            ->field('domain_id, name')
            ->order('name', 'asc')
            ->select()
            ->toArray();

        return $this->renderPage('dnspod_subdomain/index', [
            'list' => $items,
            'search' => $search,
            'domainId' => $domainId,
            'domains' => $domains,
            'pagination' => $pagination,
            'total' => $total,
            'lastSync' => $lastSync ?: '-',
        ], '子域名', 'dnspod', 'dnspod_subdomain');
    }
}