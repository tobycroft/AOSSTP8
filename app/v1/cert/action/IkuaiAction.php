<?php

namespace app\v1\cert\action;

use app\v1\cert\model\CertIkuaiModel;
use app\v1\cert\model\CertLogModel;
use app\v1\cert\model\CertUrlModel;
use Exception;
use Ikuai\Ikuai;

/**
 * 爱快路由器操作类
 * 负责登录鉴权的持久化、会话失效重登与证书下发
 */
class IkuaiAction
{
    /**
     * 鉴权失效后允许的重新登录重试次数，避免死循环
     */
    const MAX_RETRY = 1;

    /**
     * 登录并保存鉴权信息
     *
     * 账号的登录状态超时时间由 webuser/show 的 sesstimeout 字段给出（分钟），
     * 取到后按「登录时间 + 超时时间」写入 expire_time，取不到则留空
     *
     * @param array $item ao_cert_ikuai 记录
     * @return array{sess_key:string,last_login_time:string,expire_time:?string,sess_timeout:?int}
     * @throws Exception
     */
    public static function login(array $item): array
    {
        $id = intval($item['id'] ?? 0);
        $loginAt = date('Y-m-d H:i:s');
        $client = new Ikuai((string)$item['api']);

        try {
            $sessKey = $client->login((string)$item['username'], (string)$item['password']);
        } catch (Exception $e) {
            self::saveLoginState($id, '', null, null, null, $e->getMessage());
            self::log($item, (string)($item['cert_name'] ?? ''), 0, ['event' => 'login_fail', 'error' => $e->getMessage()]);
            throw $e;
        }

        $sessTimeout = $client->getSessionTimeout((string)$item['username']);
        $expireTime = $sessTimeout ? date('Y-m-d H:i:s', strtotime($loginAt) + $sessTimeout * 60) : null;

        self::saveLoginState($id, $sessKey, $loginAt, $expireTime, $sessTimeout, '');

        return [
            'sess_key' => $sessKey,
            'last_login_time' => $loginAt,
            'expire_time' => $expireTime,
            'sess_timeout' => $sessTimeout,
        ];
    }

    /**
     * 获取带登录态的客户端，sess_key 缺失或已过期时先登录
     *
     * @param array $item ao_cert_ikuai 记录
     * @throws Exception
     */
    public static function client(array $item): Ikuai
    {
        $client = new Ikuai((string)$item['api'], (string)($item['sess_key'] ?? ''));

        if (!$client->hasSessKey() || self::isExpired($item)) {
            $sess = self::login($item);
            $client->setSessKey($sess['sess_key']);
        }

        return $client;
    }

    /**
     * 按证书名称下发证书到指定路由器
     *
     * 证书内容取自 ao_cert_url 的 publickey/privatekey，
     * 若为空则回落到 SiteAction::updatessl 从证书 URL 重新拉取
     *
     * @param string $certName 证书名称，对应 ao_cert_url.cert
     * @param array $item ao_cert_ikuai 记录
     * @return array 爱快接口返回数据
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
            $ret = self::run($item, function (Ikuai $client) use ($crt, $key, $certName) {
                return $client->saveCert($crt, $key, $certName);
            });
        } catch (Exception $e) {
            self::log($item, $certName, 0, [
                'event' => 'save_cert',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        self::log($item, $certName, 1, [
            'event' => 'save_cert',
            'Result' => $ret['Result'] ?? null,
            'ErrMsg' => $ret['ErrMsg'] ?? null,
            'ca_len' => strlen($crt),
            'key_len' => strlen($key),
        ]);

        return $ret;
    }

    /**
     * 把指定证书下发到所有绑定了该证书的启用路由器
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

        $routers = CertIkuaiModel::where('status', 1)
            ->whereIn('cert_name', SiteAction::certNameCandidates($certName))
            ->select();

        foreach ($routers as $router) {
            $item = $router->toArray();
            try {
                self::updatessl($certName, $item);
                $rets['success']++;
                $rets['detail'][] = [
                    'type' => 'ikuai',
                    'api' => $item['api'],
                    'cert_name' => $certName,
                    'success' => true,
                ];
            } catch (Exception $e) {
                $rets['fail']++;
                $rets['detail'][] = [
                    'type' => 'ikuai',
                    'api' => $item['api'],
                    'cert_name' => $certName,
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $rets;
    }

    /**
     * 执行接口调用，鉴权失效时重新登录后重试，重试次数受 MAX_RETRY 限制
     *
     * @param array $item ao_cert_ikuai 记录
     * @param callable $callback 回调参数为已登录的客户端
     * @return mixed
     * @throws Exception
     */
    private static function run(array $item, callable $callback)
    {
        $client = self::client($item);
        $attempt = 0;

        while (true) {
            try {
                return $callback($client);
            } catch (Exception $e) {
                if ($attempt >= self::MAX_RETRY || !Ikuai::isAuthError($e)) {
                    throw $e;
                }
                $attempt++;

                self::log($item, (string)($item['cert_name'] ?? ''), 0, [
                    'event' => 'auto_relogin',
                    'attempt' => $attempt,
                    'reason' => $e->getMessage(),
                ]);

                $sess = self::login($item);
                $client = new Ikuai((string)$item['api'], $sess['sess_key']);
            }
        }
    }

    /**
     * 判断本地记录的会话是否已过期
     */
    private static function isExpired(array $item): bool
    {
        $expire = trim((string)($item['expire_time'] ?? ''));
        if ($expire === '') {
            // 有效期未知时交给服务端判定，由 10014 触发重登
            return false;
        }

        $timestamp = strtotime($expire);

        return $timestamp !== false && $timestamp <= time();
    }

    /**
     * 回写登录状态
     */
    private static function saveLoginState(int $id, string $sessKey, ?string $lastLoginTime, ?string $expireTime, ?int $sessTimeout, string $error): void
    {
        if ($id <= 0) {
            return;
        }

        CertIkuaiModel::where('id', $id)->update([
            'sess_key' => $sessKey,
            'last_login_time' => $lastLoginTime,
            'expire_time' => $expireTime,
            'sess_timeout' => $sessTimeout ?? 0,
            'last_error' => mb_substr($error, 0, 250),
        ]);
    }

    /**
     * 写入证书日志（type=ikuai）
     *
     * website 使用路由器 api 作为唯一标识，recv 中带上 id、cert_name 与接口返回
     *
     * @param array $item ao_cert_ikuai 记录
     * @param string $certName 证书名称
     * @param int $success 1成功 0失败
     * @param array $payload 需要记录的接口返回或异常信息
     */
    private static function log(array $item, string $certName, int $success, array $payload): void
    {
        CertLogModel::create([
            'appname' => $certName !== '' ? $certName : '-',
            'type' => 'ikuai',
            'success' => $success,
            'website' => (string)($item['api'] ?? ''),
            'recv' => mb_substr(json_encode(array_merge([
                'id' => $item['id'] ?? null,
                'cert_name' => $certName,
            ], $payload), 320), 0, 1000),
        ]);
    }
}
