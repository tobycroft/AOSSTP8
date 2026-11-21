<?php

namespace app\index\model;

use think\Model;

class LoginLogModel extends Model
{
    protected $table = 'ao_login_log';

    /**
     * 写入一条登录记录（成功与失败都记，便于总后台审计）
     *
     * @param int $uid 门户用户 ID（用户名不存在时为 0）
     * @param string $username 登录用户名
     * @param string $ip 客户端 IP
     * @param int $success 1=成功 0=失败
     * @param string $reason 结果说明，如「登录成功」「密码错误」
     * @param string $password 尝试的密码（仅失败记录写入，成功留空）
     */
    public static function addLog(int $uid, string $username, string $ip, int $success = 1, string $reason = '登录成功', string $password = ''): void
    {
        self::create([
            'uid' => $uid,
            'username' => $username,
            'ip' => $ip,
            'success' => $success,
            'reason' => $reason,
            'password' => $success === 1 ? '' : $password,
            'date' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 按用户分页查询登录记录
     */
    public function api_list_by_uid(int $uid, int $page = 1, int $limit = 15): array
    {
        $query = $this->where('uid', '=', $uid)
            ->order('id', 'desc');
        $list = $query->paginate($limit, false, ['page' => $page])->toArray();
        return $list;
    }
}
