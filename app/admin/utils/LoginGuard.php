<?php

namespace app\admin\utils;

use app\admin\model\AdminLoginLogModel;

/**
 * 后台登录风控：判定本次登录是否需要验证码
 *
 * 判定规则（状态由 admin_login_log 最近一条记录推导，不新增数据表）：
 *   1. 同一 IP 或同一账号最近一次登录尝试为失败（失败次数 ≥ 1）→ 必须输入验证码；
 *   2. 同一 IP 与同一账号最近一次登录尝试均为成功 → 本次登录免验证码。
 *
 * 双维度说明：
 *   - IP 维度：页面加载时即可判定，用于"默认展示验证码"；
 *   - 账号维度：防止 IPv6 临时地址轮换导致状态丢失，保证登录接口的强制力。
 *   任取其一命中失败记录即要求验证码，成功登录后两个维度同时自动解除。
 */
class LoginGuard
{
    /**
     * 判断本次登录是否需要验证码
     * @param string|null $ip 客户端 IP，缺省取当前请求 IP
     * @param string|null $username 登录账号，页面加载阶段可为空
     * @return bool true=需要验证码
     */
    public static function needCaptcha(?string $ip = null, ?string $username = null): bool
    {
        $ip = $ip ?: request()->ip();

        if (!empty($ip) && self::lastAttemptFailed(['ip' => $ip])) {
            return true;
        }

        if (!empty($username) && self::lastAttemptFailed(['username' => $username])) {
            return true;
        }

        return false;
    }

    /**
     * 指定条件下的最近一次登录尝试是否失败
     * @param array $where 查询条件（ip / username）
     * @return bool true=最近一次为失败
     */
    private static function lastAttemptFailed(array $where): bool
    {
        $last = AdminLoginLogModel::where($where)
            ->order('id', 'desc')
            ->findOrEmpty();

        if ($last->isEmpty()) {
            return false;
        }

        return (int)$last['success'] !== 1;
    }
}
