<?php

namespace app\v2\project\model;

use think\Model;

class ProjectModel extends Model
{
    protected $table = 'ao_project';

    public function api_find_token($token)
    {
        return $this->where('open_token', '=', $token)->findOrEmpty();
    }
}