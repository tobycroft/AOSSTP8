<?php

namespace app\admin\service;

use app\admin\model\AdminCertPveModel;
use app\admin\model\AdminCertUrlModel;
use app\v1\cert\action\PveAction;
use Exception;
use Proxmox\Proxmox;

/**
 * Proxmox VE 证书节点管理
 * 负责记录校验、增删改、下发与节点当前证书查询
 */
class CertPveService
{
    /**
     * 创建节点记录
     *
     * @param array $input 表单数据
     * @return array{id:int}
     * @throws Exception
     */
    public static function create(array $input): array
    {
        $data = self::prepareData($input, true, 0);
        $item = AdminCertPveModel::create($data);

        return ['id' => intval($item['id'])];
    }

    /**
     * 更新节点记录
     *
     * @param int $id 记录 ID
     * @param array $input 表单数据
     * @throws Exception
     */
    public static function update(int $id, array $input): void
    {
        $item = AdminCertPveModel::findOrEmpty($id);
        if ($item->isEmpty()) {
            throw new Exception('记录不存在', 404);
        }

        $item->save(self::prepareData($input, false, $id));
    }

    /**
     * 删除节点记录
     *
     * @throws Exception
     */
    public static function delete(int $id): void
    {
        $item = AdminCertPveModel::findOrEmpty($id);
        if ($item->isEmpty()) {
            throw new Exception('记录不存在', 404);
        }

        $item->delete();
    }

    /**
     * 切换启用状态
     *
     * @return int 切换后的状态
     * @throws Exception
     */
    public static function toggleStatus(int $id): int
    {
        if (!$id) {
            throw new Exception('缺少参数[id]', 400);
        }

        $item = AdminCertPveModel::findOrEmpty($id);
        if ($item->isEmpty()) {
            throw new Exception('记录不存在', 404);
        }

        $status = $item['status'] == 1 ? 0 : 1;
        $item->save(['status' => $status]);

        return $status;
    }

    /**
     * 批量删除
     *
     * @param array $ids 记录 ID 列表
     * @return int 实际删除数量
     * @throws Exception
     */
    public static function batchDelete(array $ids): int
    {
        $count = 0;
        foreach ($ids as $id) {
            $id = intval($id);
            if ($id <= 0) {
                continue;
            }

            $item = AdminCertPveModel::findOrEmpty($id);
            if (!$item->isEmpty()) {
                $item->delete();
                $count++;
            }
        }

        return $count;
    }

    /**
     * 下发证书到单个节点
     *
     * @return array 下发结果
     * @throws Exception
     */
    public static function deploy(int $id): array
    {
        $item = AdminCertPveModel::findOrEmpty($id);
        if ($item->isEmpty()) {
            throw new Exception('记录不存在', 404);
        }

        $certName = trim((string)$item['cert_name']);
        if ($certName === '') {
            throw new Exception('请先为该节点绑定证书名称', 400);
        }

        try {
            PveAction::updatessl($certName, $item->toArray());
        } catch (Exception $e) {
            throw new Exception('下发失败: ' . $e->getMessage(), 500);
        }

        return [
            'cert_name' => $certName,
            'node' => (string)$item['node'],
            'api' => (string)$item['api'],
        ];
    }

    /**
     * 一键下发：把每个启用且已绑定证书的节点按其绑定证书逐个下发
     *
     * @return array{success:int,fail:int,detail:array}
     * @throws Exception
     */
    public static function deployAll(): array
    {
        $nodes = AdminCertPveModel::where('status', 1)
            ->where('cert_name', '<>', '')
            ->select();

        if (count($nodes) === 0) {
            throw new Exception('没有可下发的节点（需启用且已绑定证书）', 404);
        }

        $rets = [
            'success' => 0,
            'fail' => 0,
            'detail' => [],
        ];

        foreach ($nodes as $node) {
            $item = $node->toArray();
            try {
                PveAction::updatessl((string)$item['cert_name'], $item);
                $rets['success']++;
                $rets['detail'][] = [
                    'api' => $item['api'],
                    'node' => $item['node'],
                    'cert_name' => $item['cert_name'],
                    'success' => true,
                ];
            } catch (Exception $e) {
                $rets['fail']++;
                $rets['detail'][] = [
                    'api' => $item['api'],
                    'node' => $item['node'],
                    'cert_name' => $item['cert_name'],
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $rets;
    }

    /**
     * 测试连接并返回节点当前证书
     *
     * @return array{node:string,count:int,items:array}
     * @throws Exception
     */
    public static function testConnection(int $id): array
    {
        $item = AdminCertPveModel::findOrEmpty($id);
        if ($item->isEmpty()) {
            throw new Exception('记录不存在', 404);
        }

        try {
            $infos = PveAction::info($item->toArray());
        } catch (Exception $e) {
            AdminCertPveModel::where('id', $id)->update([
                'last_error' => mb_substr($e->getMessage(), 0, 250),
            ]);
            throw new Exception($e->getMessage(), 500);
        }

        AdminCertPveModel::where('id', $id)->update(['last_error' => '']);

        $items = [];
        foreach ($infos as $info) {
            $items[] = [
                'filename' => (string)($info['filename'] ?? ''),
                'subject' => (string)($info['subject'] ?? ''),
                'issuer' => (string)($info['issuer'] ?? ''),
                'notbefore' => self::formatTimestamp($info['notbefore'] ?? null),
                'notafter' => self::formatTimestamp($info['notafter'] ?? null),
                'fingerprint' => (string)($info['fingerprint'] ?? ''),
            ];
        }

        return [
            'node' => (string)$item['node'],
            'count' => count($items),
            'items' => $items,
        ];
    }

    /**
     * 查看节点绑定的证书内容
     *
     * @return array{cert_name:string,publickey:string,privatekey:string}
     * @throws Exception
     */
    public static function certificate(int $id): array
    {
        $item = AdminCertPveModel::findOrEmpty($id);
        if ($item->isEmpty()) {
            throw new Exception('记录不存在', 404);
        }

        $certName = trim((string)$item['cert_name']);
        if ($certName === '') {
            throw new Exception('该节点尚未绑定证书', 400);
        }

        $certUrl = AdminCertUrlModel::where('cert', $certName)->find();
        if (!$certUrl) {
            throw new Exception('证书URL中未找到证书: ' . $certName, 404);
        }

        return [
            'cert_name' => $certName,
            'publickey' => (string)$certUrl['publickey'],
            'privatekey' => (string)$certUrl['privatekey'],
        ];
    }

    /**
     * 校验并整理表单数据
     *
     * @param array $input 表单数据
     * @param bool $isCreate 是否为新增
     * @param int $id 当前记录 ID
     * @return array
     * @throws Exception
     */
    private static function prepareData(array $input, bool $isCreate, int $id): array
    {
        $api = trim((string)($input['api'] ?? ''));
        if ($api === '') {
            throw new Exception('PVE API 地址不能为空', 400);
        }

        try {
            $api = Proxmox::normalizeBaseUrl($api);
        } catch (Exception $e) {
            throw new Exception($e->getMessage(), 400);
        }

        $node = trim((string)($input['node'] ?? ''));
        if ($node === '') {
            throw new Exception('PVE 节点名称不能为空', 400);
        }
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $node)) {
            throw new Exception('PVE 节点名称只能包含字母、数字、点、下划线与短横线', 400);
        }

        $tokenId = Proxmox::normalizeTokenId((string)($input['token_id'] ?? ''));
        if ($tokenId === '') {
            throw new Exception('API Token 标识不能为空', 400);
        }
        if (!Proxmox::isValidTokenId($tokenId)) {
            throw new Exception('API Token 标识格式不正确，应为 user@realm!tokenid', 400);
        }

        $tokenSecret = trim((string)($input['token_secret'] ?? ''));
        if ($isCreate && $tokenSecret === '') {
            throw new Exception('API Token 密钥不能为空', 400);
        }

        if (AdminCertPveModel::where('api', $api)->where('node', $node)->where('id', '<>', $id)->find()) {
            throw new Exception('该 PVE 地址与节点已存在，请修改后再保存', 400);
        }

        $data = [
            'api' => $api,
            'node' => $node,
            'token_id' => $tokenId,
            'cert_name' => trim((string)($input['cert_name'] ?? '')),
            'verify_tls' => intval($input['verify_tls'] ?? 0) ? 1 : 0,
            'restart_proxy' => intval($input['restart_proxy'] ?? 1) ? 1 : 0,
            'status' => intval($input['status'] ?? 1) ? 1 : 0,
        ];

        // 编辑时密钥留空表示不修改
        if ($tokenSecret !== '') {
            $data['token_secret'] = $tokenSecret;
        }

        return $data;
    }

    /**
     * 格式化 PVE 返回的 UNIX 时间戳
     */
    private static function formatTimestamp(mixed $timestamp): string
    {
        $timestamp = intval($timestamp);

        return $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : '-';
    }
}
