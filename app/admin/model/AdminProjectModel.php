<?php

namespace app\admin\model;

use think\Model;

class AdminProjectModel extends Model
{
    protected $table = 'ao_ak';
    protected $pk = 'appid';

    public function api_list($page, $limit)
    {
        return $this->order('appid', 'desc')
            ->paginate($limit, false, ['page' => $page])
            ->toArray();
    }
}