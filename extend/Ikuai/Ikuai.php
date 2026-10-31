<?php

namespace Ikuai;

use think\Exception;

/**
 * 爱快路由器 API 客户端
 *
 * 鉴权逻辑参考抓包结果：
 * 1、POST {base}/Action/login，登录参数需要同时提交 md5 密码与 base64("salt_11" + 密码)
 * 2、登录响应头 Set-Cookie 中的 sess_key 即后续调用的凭证
 * 3、POST {base}/Action/call，携带 Cookie: sess_key=xxx 执行业务接口
 * 4、鉴权失效时返回 {"ErrMsg":"no login authentication","Result":10014}
 */
class Ikuai
{
    /**
     * 登录接口
     */
    const LOGIN_ACTION = '/Action/login';

    /**
     * 业务接口
     */
    const CALL_ACTION = '/Action/call';

    /**
     * 登录密码加密盐值
     */
    const PASSWORD_SALT = 'salt_11';

    /**
     * 业务接口成功返回码
     */
    const RESULT_SUCCESS = 30000;

    /**
     * 登录态失效返回码
     */
    const RESULT_NO_LOGIN = 10014;

    /**
     * 爱快路由器管理地址
     */
    private string $baseUrl;

    /**
     * 登录态 sess_key
     */
    private string $sessKey;

    /**
     * 最近一次错误信息
     */
    private string $error = '';

    /**
     * 请求超时时间（秒）
     */
    private int $timeout;

    /**
     * 是否校验路由器证书
     *
     * 爱快默认使用自签证书，远程访问时证书还可能是过期的，
     * 一旦开启校验，定时续期任务会直接跑不通，因此默认关闭
     */
    private bool $verifyCertificate;

    /**
     * @param string $baseUrl 路由器管理地址，如 http://192.168.1.1:9600
     * @param string $sessKey 已保存的登录态，为空表示尚未登录
     * @param int $timeout 请求超时时间
     * @param bool $verifyCertificate 是否校验路由器证书，默认不校验
     */
    public function __construct(string $baseUrl, string $sessKey = '', int $timeout = 15, bool $verifyCertificate = false)
    {
        $this->baseUrl = rtrim(trim($baseUrl), '/');
        $this->sessKey = $sessKey;
        $this->timeout = $timeout;
        $this->verifyCertificate = $verifyCertificate;
    }

    public function getError(): string
    {
        return $this->error;
    }

    public function getSessKey(): string
    {
        return $this->sessKey;
    }

    public function setSessKey(string $sessKey): void
    {
        $this->sessKey = $sessKey;
    }

    public function hasSessKey(): bool
    {
        return !empty($this->sessKey);
    }

    /**
     * 登录路由器并返回 sess_key
     *
     * @param string $username 登录用户名
     * @param string $password 登录密码（明文）
     * @return string 登录成功后返回的 sess_key
     * @throws Exception
     */
    public function login(string $username, string $password): string
    {
        $body = json_encode([
            'username' => $username,
            'passwd' => md5($password),
            'pass' => base64_encode(self::PASSWORD_SALT . $password),
            'remember_password' => '',
        ], 320);

        $ret = $this->request(self::LOGIN_ACTION, $body, false);

        $this->parse($ret['body'], '爱快登录');
        if (!preg_match('/sess_key=([^;]+)/i', $ret['header'], $matches)) {
            $this->error = '爱快登录成功但未返回 sess_key';
            throw new Exception($this->error);
        }

        $this->sessKey = trim($matches[1], '"\'');
        if (empty($this->sessKey)) {
            $this->error = '爱快登录返回的 sess_key 为空';
            throw new Exception($this->error);
        }

        return $this->sessKey;
    }

    /**
     * 读取账号设定的登录状态超时时间（分钟）
     *
     * 对应后台「系统设置 - 登录管理 - 账号设置 - 登录状态超时时间」，
     * 由 webuser/show 接口的 sesstimeout 字段返回
     *
     * @param string $username 登录用户名
     * @return int|null 成功返回分钟数，取不到返回 null
     */
    public function getSessionTimeout(string $username): ?int
    {
        try {
            $ret = $this->call('webuser', 'show', ['FILTER1' => 'username,=,' . $username]);
            $rows = $ret['Data']['data'] ?? [];
            foreach ((array)$rows as $row) {
                if (isset($row['username']) && $row['username'] === $username) {
                    $timeout = intval($row['sesstimeout'] ?? 0);
                    return $timeout > 0 ? $timeout : null;
                }
            }
        } catch (Exception $e) {
            // 取不到超时时间不影响登录，由调用方按未知处理
        }

        return null;
    }

    /**
     * 调用爱快业务接口
     *
     * @param string $funcName 模块名，如 key_manager
     * @param string $action 操作名，如 show/save/add/edit/del
     * @param array $param 业务参数
     * @return array 接口返回的原始数据
     * @throws Exception
     */
    public function call(string $funcName, string $action, array $param = []): array
    {
        if (!$this->hasSessKey()) {
            $this->error = '调用爱快接口前需要先登录';
            throw new Exception($this->error, self::RESULT_NO_LOGIN);
        }

        $body = json_encode([
            'func_name' => $funcName,
            'action' => $action,
            'param' => $param,
        ], 320);

        $ret = $this->request(self::CALL_ACTION, $body);

        return $this->parse($ret['body'], '爱快接口[' . $funcName . '.' . $action . ']');
    }

    /**
     * 保存 WEB 管理页面的自定义 SSL 证书
     *
     * 抓包确认的报文（3.7.16）：
     * POST /Action/call
     * {"func_name":"key_manager","action":"save","param":{
     *     "comment":"域名","ca":"-----BEGIN#CERTIFICATE-----@...",
     *     "key":"-----BEGIN#RSA#PRIVATE#KEY-----@...","id":1,"enabled":"yes"}}
     * 其中证书内容用 # 代替空格、@ 代替换行
     *
     * @param string $cert 证书内容（CRT）
     * @param string $key 私钥内容（KEY）
     * @param string $remark 备注，一般为域名
     * @param int $id 证书配置 ID，抓包确认固定为 1
     * @return array
     * @throws Exception
     */
    public function saveCert(string $cert, string $key, string $remark = '', int $id = 1): array
    {
        return $this->call('key_manager', 'save', [
            'comment' => $remark,
            'ca' => self::encodePem($cert),
            'key' => self::encodePem($key),
            'id' => $id,
            'enabled' => 'yes',
        ]);
    }

    /**
     * 判断异常是否为登录态失效
     */
    public static function isAuthError(\Throwable $e): bool
    {
        if ($e->getCode() === self::RESULT_NO_LOGIN) {
            return true;
        }

        $message = strtolower($e->getMessage());
        foreach (['no login authentication', '登录态', 'sess_key', '非 json'] as $keyword) {
            if (str_contains($message, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 按爱快 WEB 端的提交格式处理 PEM 内容：换行转 @、空格转 #
     */
    private static function encodePem(string $pem): string
    {
        $pem = str_replace(["\r\n", "\r", "\n"], '@', trim($pem));
        return str_replace(' ', '#', $pem);
    }

    /**
     * 发送 JSON 请求
     *
     * @param string $path 接口路径
     * @param string $body JSON 请求体
     * @param bool $withSession 是否携带 sess_key
     * @return array{header:string,body:string}
     * @throws Exception
     */
    private function request(string $path, string $body, bool $withSession = true): array
    {
        $headers = [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($body),
        ];
        if ($withSession) {
            $headers[] = 'Cookie: sess_key=' . $this->sessKey;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->baseUrl . $path);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        // 爱快多为自签证书，远程访问时证书还可能已过期，默认不校验证书
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->verifyCertificate);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->verifyCertificate ? 2 : 0);
        // 开启「强制https WEB访问」后 http 入口会 302 到 https，需要跟随跳转并保持 POST
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_POSTREDIR, CURL_REDIR_POST_ALL);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);

        $response = curl_exec($ch);
        if ($response === false) {
            $this->error = '爱快接口请求失败: ' . curl_error($ch);
            throw new Exception($this->error);
        }

        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode >= 400) {
            $this->error = '爱快接口返回 HTTP ' . $httpCode;
            throw new Exception($this->error);
        }

        return [
            'header' => substr($response, 0, $headerSize),
            'body' => substr($response, $headerSize),
        ];
    }

    /**
     * 解析响应内容并校验业务状态
     *
     * @throws Exception
     */
    private function parse(string $content, string $scene): array
    {
        $data = json_decode($content, true);
        if (!is_array($data)) {
            // sess_key 失效时爱快会返回登录页 HTML 而不是 JSON
            $this->error = $scene . '失败: 返回内容非 JSON，登录态可能已失效';
            throw new Exception($this->error, self::RESULT_NO_LOGIN);
        }

        $result = intval($data['Result'] ?? $data['code'] ?? 0);
        $message = (string)($data['ErrMsg'] ?? $data['message'] ?? '');

        if ($result === self::RESULT_NO_LOGIN) {
            $this->error = $scene . '失败: 登录态已失效';
            throw new Exception($this->error, self::RESULT_NO_LOGIN);
        }
        if ($message !== '' && strcasecmp($message, 'Success') !== 0) {
            $this->error = $scene . '失败: ' . $message;
            throw new Exception($this->error, $result);
        }
        if ($message === '' && $result !== 0 && $result !== self::RESULT_SUCCESS) {
            $this->error = $scene . '失败: Result=' . $result;
            throw new Exception($this->error, $result);
        }

        return $data;
    }
}
