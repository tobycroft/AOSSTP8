<?php

namespace app\index\utils;

use think\facade\Db;
use think\facade\Log;

/**
 * 登录失败守卫（门户 console 登录验证码策略）
 *
 * 规则：
 *   1. 同一 IP 在窗口期内登录失败 ≥ 1 次，后续登录必须输入验证码；
 *   2. 任意一次登录成功后立即清除该 IP 的失败状态，下次登录不再需要验证码；
 *   3. 失败状态窗口 2 小时，每次失败重新计时（滑动窗口）。
 *
 * 存储：数据表 ao_login_fail（每个 IP 一行，登录成功即删除该行），
 *      建表脚本见 sql/20261116_create_ao_login_fail.sql。
 * 容错：表缺失或数据库异常时降级为“不需要验证码”并写一条 warning 日志，
 *      避免存储故障直接阻断正常登录（可用性优先）。
 */
class LoginGuard
{
    /** 失败状态窗口（秒），每次失败重新计时 */
    const TTL = 7200;

    /** 失败状态表 */
    const TABLE = 'ao_login_fail';

    /** 本次请求是否已写过降级日志（避免刷日志） */
    private static $logged = false;

    /**
     * 读取该 IP 在窗口期内的失败次数
     */
    public static function failCount(string $ip): int
    {
        try {
            $count = Db::table(self::TABLE)
                ->where('ip', '=', $ip)
                ->where('last_fail', '>=', date('Y-m-d H:i:s', time() - self::TTL))
                ->value('fail_count');
            return intval($count);
        } catch (\Throwable $e) {
            self::degrade($e);
            return 0;
        }
    }

    /**
     * 该 IP 是否需要验证码
     */
    public static function needCaptcha(string $ip): bool
    {
        return self::failCount($ip) >= 1;
    }

    /**
     * 记录一次登录失败，返回窗口期内的累计失败次数
     *
     * 用单条 upsert 完成“新增或累加”，并发安全；窗口超期后重新从 1 计。
     */
    public static function markFail(string $ip): int
    {
        try {
            $now = date('Y-m-d H:i:s');
            $windowStart = date('Y-m-d H:i:s', time() - self::TTL);
            Db::execute(
                'INSERT INTO `' . self::TABLE . '` (`ip`, `fail_count`, `last_fail`, `change_date`, `date`) '
                . 'VALUES (?, 1, ?, ?, ?) '
                . 'ON DUPLICATE KEY UPDATE '
                . '`fail_count` = IF(`last_fail` < ?, 1, `fail_count` + 1), '
                . '`last_fail` = ?, `change_date` = ?',
                [$ip, $now, $now, $now, $windowStart, $now, $now]
            );
            $count = Db::table(self::TABLE)->where('ip', '=', $ip)->value('fail_count');
            return intval($count);
        } catch (\Throwable $e) {
            self::degrade($e);
            return 0;
        }
    }

    /**
     * 登录成功后清除该 IP 的失败状态
     */
    public static function clear(string $ip): void
    {
        try {
            Db::table(self::TABLE)->where('ip', '=', $ip)->delete();
        } catch (\Throwable $e) {
            self::degrade($e);
        }
    }

    /**
     * 存储不可用时记录一次降级日志
     */
    private static function degrade(\Throwable $e): void
    {
        if (self::$logged) {
            return;
        }
        self::$logged = true;
        Log::warning('[LoginGuard] 登录失败状态表不可用，验证码策略降级（不强制验证码）：' . $e->getMessage());
    }
}
