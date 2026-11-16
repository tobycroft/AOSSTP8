<?php

namespace app\v3\ai\controller;

use Aoss\Envelope;
use app\v3\ai\model\AiModel;
use BaseController\CommonController;
use Input;
use Ret;

/**
 * v3 开放接口基类：凭证信封鉴权
 *
 * 请求方不提交凭证本身，改为提交三项：
 *   X-Aoss-Ts    秒级 Unix 时间戳，每次请求动态生成
 *   X-Aoss-Kid   substr(sha256(secret), 0, 8)，仅用于服务端定位记录
 *   X-Aoss-Auth  AES-256-GCM 密文，明文为「secret|ts」，密钥由 secret 派生
 *
 * 凭证为服务端签发的 64 位随机十六进制串（ao_ai.secret），
 * 其熵直接决定信封的机密性上限。无法设置请求头的调用方
 * 可用同名参数 ts / kid / auth 走表单或查询串，鉴权逻辑完全一致。
 */
class index extends CommonController
{
    /** 凭证时间窗（秒），超出即视为过期请求 */
    protected const TS_WINDOW = 300;

    /** 已鉴权的凭证 */
    public mixed $secret;

    /** 已鉴权的项目配置（ao_ai 行 + 绑定的 agnes API 配置） */
    public mixed $proc;

    public function initialize()
    {
        set_time_limit(0);
        parent::initialize();

        $ts = $this->authParam('x-aoss-ts', 'ts');
        $kid = $this->authParam('x-aoss-kid', 'kid');
        $auth = $this->authParam('x-aoss-auth', 'auth');

        if ($ts === '') {
            Ret::Fail(400, null, '缺少参数[ts]');
        }
        if (preg_match('/^\d{1,10}$/', $ts) !== 1) {
            Ret::Fail(400, null, '参数[ts]格式错误');
        }
        if ($kid === '') {
            Ret::Fail(400, null, '缺少参数[kid]');
        }
        if ($auth === '') {
            Ret::Fail(400, null, '缺少参数[auth]');
        }

        // 时间窗校验先于解密执行，避免为过期请求付出解密开销
        $now = time();
        if (abs($now - intval($ts)) > self::TS_WINDOW) {
            Ret::Fail(401, ['server_time' => $now], '请求已过期，允许偏差 ' . self::TS_WINDOW . ' 秒');
        }

        $proc = AiModel::api_find_kid($kid);
        if (empty($proc)) {
            Ret::Fail(401, null, '项目不可用');
        }

        // secret 即密钥：用 kid 命中的凭证解密
        $plain = Envelope::open($auth, (string)$proc['secret']);
        if ($plain === null) {
            Ret::Fail(401, null, '凭证无效');
        }

        // 密文内凭证必须与 kid 命中的记录完全一致，防止跨项目挪用
        if (!hash_equals((string)$proc['secret'], $plain['secret'])) {
            Ret::Fail(401, null, '项目不可用');
        }
        // 内层 ts 必须与外层一致，防止截获密文后替换时间戳延长时间窗
        if (preg_match('/^\d{1,10}$/', $plain['ts']) !== 1 || intval($plain['ts']) !== intval($ts)) {
            Ret::Fail(401, null, '凭证时间戳不匹配');
        }

        $this->secret = $proc['secret'];
        $this->proc = $proc;
    }

    /**
     * 读取鉴权参数：优先取请求头，其次取表单/查询串
     *
     * @param string $header 请求头名（全小写）
     * @param string $field 表单/查询串参数名
     * @return string
     */
    private function authParam(string $header, string $field): string
    {
        $value = (string)request()->header($header, '');
        if ($value !== '') {
            return $value;
        }
        return Input::Combi($field, false);
    }
}
