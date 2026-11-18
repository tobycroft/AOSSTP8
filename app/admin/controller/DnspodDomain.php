<?php

namespace app\admin\controller;

use app\admin\model\AdminDnspodDomainModel;
use app\admin\model\AdminDnspodModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;
use Dnspod\Dnspod as DnspodClient;
use Exception;
use Ret;
use think\facade\Db;

class DnspodDomain extends CommonController
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

        $query = Db::name('ao_dnspod_domain')
            ->alias('d')
            ->leftJoin('ao_dnspod n', 'd.dnspod_id = n.id')
            ->field('d.*, n.name as config_name')
            ->order('d.id', 'desc');

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('d.name', 'like', '%' . $search . '%');
                $q->whereOr('d.punycode', 'like', '%' . $search . '%');
                $q->whereOr('n.name', 'like', '%' . $search . '%');
            });
        }

        $list = $query->paginate($limit, false, ['page' => $page])->toArray();

        $items = [];
        foreach ($list['data'] as $item) {
            $items[] = [
                'id' => $item['id'],
                'dnspod_id' => $item['dnspod_id'],
                'config_name' => $item['config_name'] ?? '-',
                'domain_id' => $item['domain_id'],
                'name' => $item['name'],
                'punycode' => $item['punycode'],
                'status' => $item['status'],
                'records' => $item['records'],
                'grade' => $item['grade'],
                'date' => $item['date'],
            ];
        }

        $pagination = Layout::pagination((int)$list['current_page'], max(1, (int)ceil($list['total'] / $limit)), '/admin/dnspod_domain', ['search' => $search], $limit);

        $total = (int)$list['total'];
        $lastSync = Db::name('ao_dnspod_domain')->max('date');

        return $this->renderPage('dnspod_domain/index', [
            'list' => $items,
            'search' => $search,
            'pagination' => $pagination,
            'total' => $total,
            'lastSync' => $lastSync ?: '-',
        ], '域名管理', 'dnspod', 'dnspod_domain');
    }

    /**
     * 同步所有启用的 DNSPod 账号下的域名和解析记录到本地缓存
     * 以 DNSPod 数据为准，TRUNCATE 清空本地（重置自增 id）后重新写入，相同域名去重
     */
    public function sync()
    {
        $configs = Db::name('ao_dnspod')
            ->field('id, name, api_id, api_token')
            ->where('status', 1)
            ->select()
            ->toArray();

        if (empty($configs)) {
            Ret::Fail(400, null, '没有可用的 DNSPod 配置');
        }

        $seen = [];
        $domainData = [];
        $recordData = [];
        $now = date('Y-m-d H:i:s');
        $errors = [];

        foreach ($configs as $config) {
            try {
                $client = new DnspodClient((int)$config['api_id'], (string)$config['api_token']);
                $domains = $client->domainList();
            } catch (Exception $e) {
                $errors[] = $config['name'] . ': ' . $e->getMessage();
                continue;
            }

            foreach ($domains as $domain) {
                $name = (string)($domain['name'] ?? '');
                $domainId = (int)($domain['id'] ?? 0);
                if ($name === '' || $domainId <= 0 || isset($seen[$name])) {
                    continue;
                }
                $seen[$name] = true;

                $domainData[] = [
                    'dnspod_id' => (int)$config['id'],
                    'domain_id' => $domainId,
                    'name' => $name,
                    'punycode' => (string)($domain['punycode'] ?? ''),
                    'status' => (string)($domain['status'] ?? ''),
                    'records' => (int)($domain['records'] ?? 0),
                    'grade' => (string)($domain['grade'] ?? ''),
                    'date' => $now,
                ];

                try {
                    $records = $client->recordList($domainId);
                } catch (Exception $e) {
                    $errors[] = $name . ' 记录获取失败: ' . $e->getMessage();
                    continue;
                }

                foreach ($records as $record) {
                    $recordData[] = [
                        'dnspod_id' => (int)$config['id'],
                        'domain_id' => $domainId,
                        'domain_name' => $name,
                        'record_id' => (int)($record['id'] ?? 0),
                        'name' => (string)($record['name'] ?? ''),
                        'type' => (string)($record['type'] ?? ''),
                        'value' => (string)($record['value'] ?? ''),
                        'line' => (string)($record['line'] ?? ''),
                        'ttl' => (int)($record['ttl'] ?? 0),
                        'enabled' => (string)($record['enabled'] ?? '1'),
                        'weight' => (int)($record['weight'] ?? 0),
                        'remark' => (string)($record['remark'] ?? ''),
                        'date' => $now,
                    ];
                }
            }
        }

        Db::execute('TRUNCATE TABLE ao_dnspod_domain');
        Db::execute('TRUNCATE TABLE ao_dnspod_record');

        if (!empty($domainData)) {
            Db::name('ao_dnspod_domain')->insertAll($domainData);
        }
        if (!empty($recordData)) {
            Db::name('ao_dnspod_record')->insertAll($recordData);
        }

        $msg = '同步完成，共 ' . count($domainData) . ' 个域名、' . count($recordData) . ' 条解析记录';
        if (!empty($errors)) {
            $msg .= '；' . count($errors) . ' 项获取失败';
        }

        Ret::Success(0, [
            'domainCount' => count($domainData),
            'recordCount' => count($recordData),
            'errors' => $errors,
        ], $msg);
    }
}