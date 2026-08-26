<?php

namespace app\admin\model;

use think\Model;

class AdminUserModel extends Model
{
    protected $table = 'admin_user';

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

    public function api_list($page = 1, $limit = 20)
    {
        return $this->order('id', 'desc')
            ->paginate($limit, false, ['page' => $page]);
    }
}