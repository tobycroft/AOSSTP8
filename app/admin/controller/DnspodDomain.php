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
            ->order('d.domain_id', 'desc');

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
                'id' => $item['domain_id'],
                'domain_id' => $item['domain_id'],
                'dnspod_id' => $item['dnspod_id'],
                'config_name' => $item['config_name'] ?? '-',
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
     * 同步域名和解析记录到本地缓存
     * 传入 id 时仅同步该 DNSPod 配置账号下的全部域名；不传则同步所有启用账号。
     * 使用 upsert 方案：按唯一键（域名 name / 记录 domain_id+record_id）做更新或插入，
     * 保持本地自增 id 稳定，不重复清空重建。
     */
    public function sync()
    {
        $configId = input('post.id', 0, 'intval');

        $configQuery = Db::name('ao_dnspod')
            ->field('id, name, api_id, api_token');
        if ($configId > 0) {
            $configQuery->where('id', $configId);
        } else {
            $configQuery->where('status', 1);
        }
        $configs = $configQuery->select()->toArray();

        if (empty($configs)) {
            Ret::Fail(400, null, '没有可用的 DNSPod 配置');
        }

        $seen = [];
        $now = date('Y-m-d H:i:s');
        $errors = [];

        // 远端域名集合（name => domain_id），用于清理本地已不存在的域名
        $remoteDomains = [];
        // 远端记录集合（domain_id => [record_id, ...]），用于清理本地已不存在的记录
        $remoteRecords = [];
        // 记录获取成功的域名集合（记录获取失败时跳过清理，避免误删本地数据）
        $fetchedRecordDomains = [];

        $insertDomains = [];
        $updateDomains = [];
        $insertRecords = [];
        $updateRecords = [];

        // 本地现有域名 name => domain_id（did）。单配置同步时仅限定该配置下的域名，避免清理误伤其它账号数据
        $localDomainQuery = Db::name('ao_dnspod_domain');
        if ($configId > 0) {
            $localDomainQuery->where('dnspod_id', $configId);
        }
        $localDomains = $localDomainQuery->column('domain_id', 'name');
        // 本地现有记录 (domain_id,record_id) => true
        $localRecords = [];
        $localRecordQuery = Db::name('ao_dnspod_record')
            ->field('domain_id, record_id');
        if ($configId > 0) {
            $domainIds = array_values($localDomains);
            if (!empty($domainIds)) {
                $localRecordQuery->whereIn('domain_id', $domainIds);
            }
        }
        $localRecordAll = $localRecordQuery->select()->toArray();
        foreach ($localRecordAll as $lr) {
            $localRecords[$lr['domain_id'] . ',' . $lr['record_id']] = true;
        }
        unset($localRecordAll);

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

                $remoteDomains[$name] = $domainId;

                $item = [
                    'dnspod_id' => (int)$config['id'],
                    'domain_id' => $domainId,
                    'name' => $name,
                    'punycode' => (string)($domain['punycode'] ?? ''),
                    'status' => (string)($domain['status'] ?? ''),
                    'records' => (int)($domain['records'] ?? 0),
                    'grade' => (string)($domain['grade'] ?? ''),
                    'date' => $now,
                ];

                if (isset($localDomains[$name])) {
                    $updateDomains[] = $item;
                } else {
                    $insertDomains[] = $item;
                }

                try {
                    $records = $client->recordList($domainId);
                    $fetchedRecordDomains[$domainId] = true;
                } catch (Exception $e) {
                    $errors[] = $name . ' 记录获取失败: ' . $e->getMessage();
                    continue;
                }

                $remoteRecords[$domainId] = [];
                foreach ($records as $record) {
                    $recordId = (int)($record['id'] ?? 0);
                    $remoteRecords[$domainId][] = $recordId;

                    $recordItem = [
                        'dnspod_id' => (int)$config['id'],
                        'domain_id' => $domainId,
                        'domain_name' => $name,
                        'record_id' => $recordId,
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

                    $key = $domainId . ',' . $recordId;
                    if (isset($localRecords[$key])) {
                        $updateRecords[] = $recordItem;
                    } else {
                        $insertRecords[] = $recordItem;
                    }
                }
            }
        }

        Db::startTrans();
        try {
            // 1. upsert 域名（主键为 domain_id）
            foreach ($insertDomains as $item) {
                Db::name('ao_dnspod_domain')->insert($item);
            }
            foreach ($updateDomains as $item) {
                $domainId = (int)$item['domain_id'];
                Db::name('ao_dnspod_domain')->where('domain_id', $domainId)->update($item);
            }

            // 2. upsert 记录（复合主键 domain_id+record_id）
            foreach ($insertRecords as $item) {
                Db::name('ao_dnspod_record')->insert($item);
            }
            foreach ($updateRecords as $item) {
                $domainId = (int)$item['domain_id'];
                $recordId = (int)$item['record_id'];
                Db::name('ao_dnspod_record')
                    ->where('domain_id', $domainId)
                    ->where('record_id', $recordId)
                    ->update($item);
            }

            // 3. 清理本地已不存在的域名（以远端为准）
            $existsDomains = array_keys($remoteDomains);
            if (!empty($localDomains)) {
                foreach ($localDomains as $localName => $localDomainId) {
                    if (!in_array($localName, $existsDomains, true)) {
                        Db::name('ao_dnspod_domain')->where('domain_id', $localDomainId)->delete();
                        Db::name('ao_dnspod_record')->where('domain_id', $localDomainId)->delete();
                    }
                }
            }

            // 4. 清理本地已不存在的记录（以远端为准，仅针对本次记录获取成功的域名）
            if (!empty($localRecords)) {
                foreach ($localRecords as $key => $flag) {
                    [$dId, $rId] = explode(',', $key);
                    $dId = (int)$dId;
                    if (empty($fetchedRecordDomains[$dId])) {
                        continue;
                    }
                    $rId = (int)$rId;
                    $remoteList = $remoteRecords[$dId] ?? [];
                    if (!in_array($rId, $remoteList, true)) {
                        Db::name('ao_dnspod_record')
                            ->where('domain_id', $dId)
                            ->where('record_id', $rId)
                            ->delete();
                    }
                }
            }

            Db::commit();
        } catch (Exception $e) {
            Db::rollback();
            Ret::Fail(500, null, '同步失败: ' . $e->getMessage());
        }

        $msg = '同步完成，新增 ' . count($insertDomains) . ' 个域名、更新 ' . count($updateDomains) . ' 个域名；记录新增 ' . count($insertRecords) . '、更新 ' . count($updateRecords) . '';
        if (!empty($errors)) {
            $msg .= '；' . count($errors) . ' 项获取失败';
        }
        if ($configId > 0 && !empty($configs)) {
            $msg = '【' . $configs[0]['name'] . '】' . $msg;
        }

        Ret::Success(0, [
            'domainInsert' => count($insertDomains),
            'domainUpdate' => count($updateDomains),
            'recordInsert' => count($insertRecords),
            'recordUpdate' => count($updateRecords),
            'errors' => $errors,
        ], $msg);
    }
}