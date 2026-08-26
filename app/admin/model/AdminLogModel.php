<?php

namespace app\admin\model;

use think\Model;

class AdminLogModel extends Model
{
    protected $table = 'admin_log';

    public function api_list($page = 1, $limit = 20)
    {
        return $this->order('id', 'desc')
            ->paginate($limit, false, ['page' => $page]);
    }
}