<?php

namespace app\index\model;

use think\Model;

class UserModel extends Model
{
    protected $table = 'ao_user';

    public function api_find_username($username)
    {
        return $this->where('username', '=', $username)
            ->findOrEmpty();
    }

    public function api_find_id($id)
    {
        return $this->where('id', '=', $id)
            ->findOrEmpty();
    }
}
