<?php

namespace app\v2\file\model;

use think\Model;

class FileTokenModel extends Model
{
    protected $table = 'ao_attachment_token';

    public function api_find_valid($token)
    {
        return $this->where('token', '=', $token)
            ->where('expired_at', '>', date('Y-m-d H:i:s'))
            ->where('is_used', '=', 0)
            ->findOrEmpty();
    }
}