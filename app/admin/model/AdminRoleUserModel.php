<?php

namespace app\admin\model;

use think\Model;

class AdminRoleUserModel extends Model
{
    protected $table = 'admin_role_user';

    public function api_find_by_user($user_id)
    {
        return $this->where('user_id', '=', $user_id)
            ->select();
    }

    public function api_delete_by_user($user_id)
    {
        return $this->where('user_id', '=', $user_id)
            ->delete();
    }
}