<?php

namespace app\index\model;

use think\Model;

class LoginLogModel extends Model
{
    protected $table = 'ao_login_log';

    /**
     * 写入一条登录记录
     */
    public static function addLog(int $uid, string $username, string $ip): void
    {
        self::create([
            'uid' => $uid,
            'username' => $username,
            'ip' => $ip,
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
