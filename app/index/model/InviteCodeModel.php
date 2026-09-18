<?php

namespace app\index\model;

use think\Model;

class InviteCodeModel extends Model
{
    protected $table = 'ao_invite_code';

    public function api_find_code($code)
    {
        return $this->where('code', '=', $code)
            ->findOrEmpty();
    }
}
