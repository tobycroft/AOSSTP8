<?php

namespace app\v1\cert\action;

use app\v1\cert\model\CertLogModel;
use app\v1\cert\model\CertPveModel;
use app\v1\cert\model\CertUrlModel;
use Exception;
use Proxmox\Proxmox;

/**
 * Proxmox VE 节点操作类
 * 负责证书下发、节点当前证书查询与下发日志
 */
class PveAction
{
    /**
     * 查询节点信息时使用的超时时间（秒）
     */
    const QUERY_TIMEOUT = 15;

    /**
     * 下发证书时使用的超时时间（秒），证书链较大时留足余量
     */
    const DEPLOY_TIMEOUT = 30;

    /**
     * 创建 API 客户端
     *
     * @param array $item ao_cert_pve 记录
     * @throws Exception
     */
    public static function client(array $item, int $timeout = self::DEPLOY_TIMEOUT): Proxmox
    {
        return new Proxmox(
            (string)($item['api'] ?? ''),
            (string)($item['node'] ?? ''),
            (string)($item['token_id'] ?? ''),
            (string)($item['token_secret'] ?? ''),
            $timeout,
            !empty($item['verify_tls'])
        );
    }

    /**
     * 查询节点当前证书
     *
     * @param array $item ao_cert_pve 记录
     * @return array
     * @throws Exception
     */
    public static function info(array $item): array
    {
        return self::client($item, self::QUERY_TIMEOUT)->certificateInfo();
    }

    /**
     * 按证书名称下发证书到指定 PVE 节点
     *
     * 证书内容取自 ao_cert_url 的 publickey/privatekey，
     * 若为空则回落到 SiteAction::updatessl 从证书 URL 重新拉取
     *
     * @param string $certName 证书名称，对应 ao_cert_url.cert
     * @param array $item ao_cert_pve 记录
     * @return array PVE 接口返回数据
     * @throws Exception
     */
    public static function updatessl(string $certName, array $item): array
    {
        $certUrl = CertUrlModel::where('cert', $certName)->find();
        if (!$certUrl) {
            throw new Exception('未找到证书项目: ' . $certName);
        }

        $crt = (string)$certUrl['publickey'];
        $key = (string)$certUrl['privatekey'];
        if ($crt === '' || $key === '') {
            $ssl = SiteAction::updatessl($certName);
            $crt = (string)$ssl['crt'];
            $key = (string)$ssl['key'];
        }

        try {
            $ret = self::client($item)->uploadCustomCertificate(
                $crt,
                $key,
                true,
                !empty($item['restart_proxy'])
            );
        } catch (Exception $e) {
            self::saveResult($item, 0, $e->getMessage());
            self::log($item, $certName, 0, [
                'event' => 'save_cert',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        self::saveResult($item, 1, '');
        self::log($item, $certName, 1, [
            'event' => 'save_cert',
            'node' => (string)($item['node'] ?? ''),
            'force' => 1,
            'restart' => intval(!empty($item['restart_proxy'])),
            'cert_len' => strlen($crt),
            'key_len' => strlen($key),
        ]);

        return is_array($ret) ? $ret : [];
    }

    /**
     * 把指定证书下发到所有绑定了该证书的启用 PVE 节点
     *
     * @param string $certName 证书名称，对应 ao_cert_url.cert
     * @return array{success:int,fail:int,detail:array}
     */
    public static function autossl(string $certName): array
    {
        $rets = [
            'success' => 0,
            'fail' => 0,
            'detail' => [],
        ];

        $nodes = CertPveModel::where('status', 1)
            ->whereIn('cert_name', SiteAction::certNameCandidates($certName))
            ->select();

        foreach ($nodes as $node) {
            $item = $node->toArray();
            try {
                self::updatessl($certName, $item);
                $rets['success']++;
                $rets['detail'][] = [
                    'type' => 'pve',
                    'api' => $item['api'],
                    'node' => $item['node'],
                    'cert_name' => $certName,
                    'success' => true,
                ];
            } catch (Exception $e) {
                $rets['fail']++;
                $rets['detail'][] = [
                    'type' => 'pve',
                    'api' => $item['api'],
                    'node' => $item['node'],
                    'cert_name' => $certName,
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $rets;
    }

    /**
     * 回写最近一次下发结果
     */
    private static function saveResult(array $item, int $success, string $error): void
    {
        $id = intval($item['id'] ?? 0);
        if ($id <= 0) {
            return;
        }

        CertPveModel::where('id', $id)->update([
            'last_deploy_time' => date('Y-m-d H:i:s'),
            'last_deploy_status' => $success,
            'last_error' => mb_substr($error, 0, 250),
        ]);
    }

    /**
     * 写入证书日志（type=pve）
     *
     * website 使用「API 地址 (节点名)」作为唯一标识，recv 中带上 id、node 与接口返回
     *
     * @param array $item ao_cert_pve 记录
     * @param string $certName 证书名称
     * @param int $success 1成功 0失败
     * @param array $payload 需要记录的接口返回或异常信息
     */
    private static function log(array $item, string $certName, int $success, array $payload): void
    {
        $website = trim((string)($item['api'] ?? ''));
        $node = trim((string)($item['node'] ?? ''));
        if ($node !== '') {
            $website .= ' (' . $node . ')';
        }

        CertLogModel::create([
            'appname' => $certName !== '' ? $certName : '-',
            'type' => 'pve',
            'success' => $success,
            'website' => $website,
            'recv' => mb_substr(json_encode(array_merge([
                'id' => $item['id'] ?? null,
                'node' => $node,
                'cert_name' => $certName,
            ], $payload), 320), 0, 1000),
        ]);
    }
}
