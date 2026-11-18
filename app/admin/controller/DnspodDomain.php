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
     * 使用 upsert 方案：按唯一键（域名 name / 记录 domain_id+record_id）做更新或插入，
     * 保持本地自增 id 稳定，不重复清空重建。
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

        // 本地现有域名 name => id 与记录 (domain_id,record_id) => id
        $localDomains = Db::name('ao_dnspod_domain')
            ->column('id', 'name');
        $localRecords = [];
        $localRecordAll = Db::name('ao_dnspod_record')
            ->field('id, domain_id, record_id')
            ->select()
            ->toArray();
        foreach ($localRecordAll as $lr) {
            $localRecords[$lr['domain_id'] . ',' . $lr['record_id']] = $lr['id'];
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
                    $item['id'] = (int)$localDomains[$name];
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
                        $recordItem['id'] = (int)$localRecords[$key];
                        $updateRecords[] = $recordItem;
                    } else {
                        $insertRecords[] = $recordItem;
                    }
                }
            }
        }

        Db::startTrans();
        try {
            // 1. upsert 域名
            foreach ($insertDomains as $item) {
                unset($item['id']);
                Db::name('ao_dnspod_domain')->insert($item);
            }
            foreach ($updateDomains as $item) {
                $id = (int)$item['id'];
                unset($item['id']);
                Db::name('ao_dnspod_domain')->where('id', $id)->update($item);
            }

            // 2. upsert 记录
            foreach ($insertRecords as $item) {
                unset($item['id']);
                Db::name('ao_dnspod_record')->insert($item);
            }
            foreach ($updateRecords as $item) {
                $id = (int)$item['id'];
                unset($item['id']);
                Db::name('ao_dnspod_record')->where('id', $id)->update($item);
            }

            // 3. 清理本地已不存在的域名（以远端为准）
            $existsDomains = array_keys($remoteDomains);
            if (!empty($localDomains)) {
                foreach ($localDomains as $localName => $localId) {
                    if (!in_array($localName, $existsDomains, true)) {
                        // 删除该域名及其记录
                        $delDomainId = Db::name('ao_dnspod_domain')->where('name', $localName)->value('domain_id');
                        Db::name('ao_dnspod_domain')->where('id', $localId)->delete();
                        if ($delDomainId) {
                            Db::name('ao_dnspod_record')->where('domain_id', $delDomainId)->delete();
                        }
                    }
                }
            }

            // 4. 清理本地已不存在的记录（以远端为准，仅针对本次记录获取成功的域名）
            if (!empty($localRecords)) {
                foreach ($localRecords as $key => $localId) {
                    [$dId, $rId] = explode(',', $key);
                    $dId = (int)$dId;
                    if (empty($fetchedRecordDomains[$dId])) {
                        continue;
                    }
                    $rId = (int)$rId;
                    $remoteList = $remoteRecords[$dId] ?? [];
                    if (!in_array($rId, $remoteList, true)) {
                        Db::name('ao_dnspod_record')->where('id', (int)$localId)->delete();
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

        Ret::Success(0, [
            'domainInsert' => count($insertDomains),
            'domainUpdate' => count($updateDomains),
            'recordInsert' => count($insertRecords),
            'recordUpdate' => count($updateRecords),
            'errors' => $errors,
        ], $msg);
    }
}