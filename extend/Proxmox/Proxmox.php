<?php

namespace Proxmox;

use Exception;

/**
 * Proxmox VE API 客户端
 *
 * 使用 API Token 鉴权：
 *   Authorization: PVEAPIToken=<user>@<realm>!<tokenid>=<secret>
 *
 * 自定义证书接口（官方 API 文档确认）：
 *   GET /api2/json/nodes/{node}/certificates/info   查询节点当前证书
 *   POST /api2/json/nodes/{node}/certificates/custom 上传自定义证书链与私钥
 *     certificates  必填，PEM 证书链
 *     key           可选，PEM 私钥
 *     force         可选，覆盖已有自定义/ACME 证书
 *     restart       可选，上传后重启 pveproxy
 */
class Proxmox
{
    /**
     * PVE API 前缀
     */
    const API_PREFIX = '/api2/json';

    /**
     * PVE 管理地址，如 https://10.0.0.10:8006
     */
    private string $baseUrl;

    /**
     * 节点名称，如 pve
     */
    private string $node;

    /**
     * API Token 标识，格式 user@realm!tokenid
     */
    private string $tokenId;

    /**
     * API Token 密钥
     */
    private string $tokenSecret;

    /**
     * 请求超时时间（秒）
     */
    private int $timeout;

    /**
     * 是否校验 PVE 证书
     *
     * PVE 默认使用自签证书，远程访问时直接校验会失败，默认关闭
     */
    private bool $verifyTls;

    /**
     * 最近一次错误信息
     */
    private string $error = '';

    /**
     * @param string $baseUrl PVE 管理地址
     * @param string $node 节点名称
     * @param string $tokenId API Token 标识 user@realm!tokenid
     * @param string $tokenSecret API Token 密钥
     * @param int $timeout 请求超时时间
     * @param bool $verifyTls 是否校验 PVE 证书，默认不校验
     * @throws Exception
     */
    public function __construct(string $baseUrl, string $node, string $tokenId, string $tokenSecret, int $timeout = 20, bool $verifyTls = false)
    {
        $this->baseUrl = self::normalizeBaseUrl($baseUrl);

        $this->node = trim($node);
        if ($this->node === '') {
            throw new Exception('PVE 节点名称不能为空');
        }

        $this->tokenId = self::normalizeTokenId($tokenId);
        if (!self::isValidTokenId($this->tokenId)) {
            throw new Exception('API Token 标识格式不正确，应为 user@realm!tokenid');
        }

        $this->tokenSecret = trim($tokenSecret);
        if ($this->tokenSecret === '') {
            throw new Exception('API Token 密钥不能为空');
        }

        $this->timeout = $timeout;
        $this->verifyTls = $verifyTls;
    }

    /**
     * 规范化 PVE 管理地址
     *
     * 兼容用户手填的常见写法：缺少协议、结尾斜杠、误粘贴接口路径等，
     * 未填写协议时 PVE 默认按 https 处理
     *
     * @throws Exception
     */
    public static function normalizeBaseUrl(string $baseUrl): string
    {
        $url = trim($baseUrl);
        if ($url === '') {
            throw new Exception('PVE API 地址不能为空');
        }

        $url = str_replace('\\', '/', $url);

        if (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $url)) {
            $url = 'https://' . $url;
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            throw new Exception('PVE API 地址格式不正确: ' . $baseUrl);
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new Exception('PVE API 地址仅支持 http/https: ' . $baseUrl);
        }

        $host = $parts['host'];
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = '[' . $host . ']';
        }

        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $scheme . '://' . $host . $port;
    }

    /**
     * 规范化 Token 标识：去掉可能误粘贴的 PVEAPIToken= 前缀与引号
     */
    public static function normalizeTokenId(string $tokenId): string
    {
        $tokenId = trim($tokenId);
        $tokenId = preg_replace('/^PVEAPIToken\s*=\s*/i', '', $tokenId) ?? $tokenId;
        if (str_contains($tokenId, '=')) {
            $tokenId = explode('=', $tokenId, 2)[0];
        }

        return trim($tokenId, " \t\n\r\0\x0B\"'");
    }

    /**
     * 判断 Token 标识是否包含 user@realm!tokenid 形式
     */
    public static function isValidTokenId(string $tokenId): bool
    {
        return str_contains($tokenId, '@') && str_contains($tokenId, '!') && !preg_match('/\s/', $tokenId);
    }

    public function getError(): string
    {
        return $this->error;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getNode(): string
    {
        return $this->node;
    }

    /**
     * 查询节点当前证书
     *
     * @return array 证书列表，字段含 filename/subject/issuer/notbefore/notafter/fingerprint 等
     * @throws Exception
     */
    public function certificateInfo(): array
    {
        $data = $this->request('GET', '/nodes/' . rawurlencode($this->node) . '/certificates/info');

        return is_array($data) ? $data : [];
    }

    /**
     * 上传自定义证书链与私钥
     *
     * @param string $cert PEM 证书链
     * @param string $key PEM 私钥
     * @param bool $force 覆盖已有自定义/ACME 证书
     * @param bool $restart 上传后重启 pveproxy
     * @return mixed PVE 接口返回的 data
     * @throws Exception
     */
    public function uploadCustomCertificate(string $cert, string $key, bool $force = true, bool $restart = false): mixed
    {
        if (trim($cert) === '') {
            throw new Exception('证书内容不能为空');
        }

        $params = [
            'certificates' => $cert,
            'force' => $force ? '1' : '0',
            'restart' => $restart ? '1' : '0',
        ];
        if (trim($key) !== '') {
            $params['key'] = $key;
        }

        return $this->request('POST', '/nodes/' . rawurlencode($this->node) . '/certificates/custom', $params);
    }

    /**
     * 发送 PVE API 请求
     *
     * @param string $method HTTP 方法
     * @param string $path 以 /nodes/... 开头的接口路径
     * @param array $params 请求参数
     * @return mixed
     * @throws Exception
     */
    private function request(string $method, string $path, array $params = []): mixed
    {
        $url = $this->baseUrl . self::API_PREFIX . $path;
        $headers = [
            'Authorization: PVEAPIToken=' . $this->tokenId . '=' . $this->tokenSecret,
            'Accept: application/json',
        ];

        $body = null;
        if ($method === 'GET') {
            if (!empty($params)) {
                $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
            }
        } else {
            $body = http_build_query($params);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        // PVE 默认自签证书，是否校验由记录配置决定
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->verifyTls);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->verifyTls ? 2 : 0);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));

        if ($response === false) {
            $this->error = 'PVE 接口请求失败: ' . $curlError;
            throw new Exception($this->error);
        }

        $data = json_decode((string)$response, true);

        if ($httpCode >= 400) {
            $this->error = $this->buildHttpError($httpCode, $data, (string)$response);
            throw new Exception($this->error, $httpCode);
        }

        if (!is_array($data)) {
            $this->error = 'PVE 接口返回内容非 JSON: ' . mb_substr(trim((string)$response), 0, 200);
            throw new Exception($this->error, $httpCode);
        }

        return $data['data'] ?? null;
    }

    /**
     * 组装可读的 HTTP 错误信息，尽量把 PVE 的字段级错误原样回显
     */
    private function buildHttpError(int $httpCode, mixed $data, string $raw): string
    {
        $message = match ($httpCode) {
            401 => 'PVE 认证失败（401）：请检查 Token 标识与密钥是否正确、Token 是否启用，并确认权限包含 Sys.Modify 或存在对应节点权限',
            403 => 'PVE 权限不足（403）：当前 Token 没有修改节点证书的权限（需要 /nodes/{node} 的 Sys.Modify）',
            404 => 'PVE 接口不存在（404）：请检查节点名称是否正确',
            595 => 'PVE 参数校验失败（595）',
            default => 'PVE 接口返回 HTTP ' . $httpCode,
        };

        $details = [];
        if (is_array($data)) {
            if (!empty($data['errors']) && is_array($data['errors'])) {
                foreach ($data['errors'] as $field => $error) {
                    $text = is_array($error) ? json_encode($error, 320) : (string)$error;
                    $details[] = (is_string($field) ? $field . ': ' : '') . $text;
                }
            }
            if (!empty($data['message'])) {
                $details[] = (string)$data['message'];
            }
        }

        if (empty($details)) {
            $raw = trim($raw);
            if ($raw !== '') {
                $details[] = mb_substr($raw, 0, 300);
            }
        }

        return $details ? $message . '；' . implode('；', $details) : $message;
    }
}
