<?php

namespace app\admin\model;

use think\Model;

class AdminInviteCodeModel extends Model
{
    protected $table = 'ao_invite_code';

    public function api_find_code($code)
    {
        return $this->where('code', '=', $code)
            ->findOrEmpty();
    }

    public function api_list($page = 1, $limit = 20)
    {
        return $this->order('id', 'desc')
            ->paginate($limit, false, ['page' => $page]);
    }
}
