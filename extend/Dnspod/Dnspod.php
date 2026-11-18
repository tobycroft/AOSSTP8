<?php

namespace Dnspod;

use think\Exception;

/**
 * DNSPod 官方 API 客户端
 *
 * 使用 DNSPod 旧版 API（dnsapi.cn），鉴权方式为 login_token = API_ID,API_Token。
 * 文档：https://docs.dnspod.cn/api/
 */
class Dnspod
{
    /**
     * DNSPod API 基础地址
     */
    const BASE_URL = 'https://dnsapi.cn/';

    /**
     * DNSPod API ID
     */
    private int $apiId;

    /**
     * DNSPod API Token
     */
    private string $apiToken;

    /**
     * 请求超时时间（秒）
     */
    private int $timeout;

    /**
     * @param int $apiId DNSPod API ID
     * @param string $apiToken DNSPod API Token
     * @param int $timeout 请求超时时间
     */
    public function __construct(int $apiId, string $apiToken, int $timeout = 15)
    {
        $this->apiId = $apiId;
        $this->apiToken = $apiToken;
        $this->timeout = $timeout;
    }

    /**
     * 调用 DNSPod 接口
     *
     * @param string $action 接口动作，如 Domain.List、Record.List
     * @param array $params 业务参数
     * @return array 解析后的响应数组
     * @throws Exception 请求失败或业务错误时抛出
     */
    public function request(string $action, array $params = []): array
    {
        $url = self::BASE_URL . $action;

        $postData = array_merge([
            'login_token' => $this->apiId . ',' . $this->apiToken,
            'format' => 'json',
            'lang' => 'cn',
            'error_on_empty' => 'no',
        ], $params);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'User-Agent: AOSSTP8 DNSPod Client/1.0',
        ]);

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);

        if ($response === false) {
            throw new Exception('DNSPod 请求失败: ' . $error . ' (errno=' . $errno . ')');
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new Exception('DNSPod 响应解析失败: ' . mb_substr($response, 0, 200));
        }

        $code = (int)($data['status']['code'] ?? -1);
        $message = (string)($data['status']['message'] ?? '未知错误');
        if ($code !== 1) {
            throw new Exception('DNSPod 接口错误 [' . $code . ']: ' . $message);
        }

        return $data;
    }

    /**
     * 获取域名列表
     *
     * @return array 域名列表
     * @throws Exception
     */
    public function domainList(): array
    {
        $data = $this->request('Domain.List');
        return $data['domains'] ?? [];
    }

    /**
     * 获取解析记录列表
     *
     * @param int $domainId 域名 ID
     * @param array $params 可选参数：sub_domain, record_type, offset, length
     * @return array 记录列表
     * @throws Exception
     */
    public function recordList(int $domainId, array $params = []): array
    {
        $params['domain_id'] = $domainId;
        $data = $this->request('Record.List', $params);
        return $data['records'] ?? [];
    }

    /**
     * 创建解析记录
     *
     * @param int $domainId 域名 ID
     * @param string $subDomain 子域名
     * @param string $recordType 记录类型，如 A、CNAME、TXT
     * @param string $value 记录值
     * @param string $recordLine 线路，默认默认
     * @param int $ttl TTL
     * @return array 创建的记录信息
     * @throws Exception
     */
    public function recordCreate(int $domainId, string $subDomain, string $recordType, string $value, string $recordLine = '默认', int $ttl = 600): array
    {
        $params = [
            'domain_id' => $domainId,
            'sub_domain' => $subDomain,
            'record_type' => $recordType,
            'record_line' => $recordLine,
            'value' => $value,
            'ttl' => $ttl,
        ];
        $data = $this->request('Record.Create', $params);
        return $data['record'] ?? [];
    }

    /**
     * 修改解析记录
     *
     * @param int $domainId 域名 ID
     * @param int $recordId 记录 ID
     * @param string $subDomain 子域名
     * @param string $recordType 记录类型
     * @param string $value 记录值
     * @param string $recordLine 线路
     * @param int $ttl TTL
     * @return array 修改后的记录信息
     * @throws Exception
     */
    public function recordModify(int $domainId, int $recordId, string $subDomain, string $recordType, string $value, string $recordLine = '默认', int $ttl = 600): array
    {
        $params = [
            'domain_id' => $domainId,
            'record_id' => $recordId,
            'sub_domain' => $subDomain,
            'record_type' => $recordType,
            'record_line' => $recordLine,
            'value' => $value,
            'ttl' => $ttl,
        ];
        $data = $this->request('Record.Modify', $params);
        return $data['record'] ?? [];
    }

    /**
     * 删除解析记录
     *
     * @param int $domainId 域名 ID
     * @param int $recordId 记录 ID
     * @throws Exception
     */
    public function recordRemove(int $domainId, int $recordId): void
    {
        $this->request('Record.Remove', [
            'domain_id' => $domainId,
            'record_id' => $recordId,
        ]);
    }

    /**
     * 修改记录状态（启用/禁用）
     *
     * @param int $domainId 域名 ID
     * @param int $recordId 记录 ID
     * @param string $status 状态：enable / disable
     * @throws Exception
     */
    public function recordStatus(int $domainId, int $recordId, string $status): void
    {
        $this->request('Record.Status', [
            'domain_id' => $domainId,
            'record_id' => $recordId,
            'status' => $status,
        ]);
    }

    /**
     * 测试连通性：调用一次 Domain.Version 验证密钥是否可用
     *
     * @return array 版本信息
     * @throws Exception
     */
    public function test(): array
    {
        return $this->request('Info.Version');
    }
}