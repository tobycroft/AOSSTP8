<?php

namespace app\v2\project\model;

use think\Model;

class ProjectModel extends Model
{
    protected $table = 'ao_project';

    public function api_find_token($token)
    {
        return $this->where('open_token', '=', $token)
            ->where('is_avail', '=', 1)
            ->findOrEmpty();
    }

    public function api_find_appid($appid)
    {
        return $this->where('appid', '=', $appid)
            ->where('is_avail', '=', 1)
            ->findOrEmpty();
    }
}