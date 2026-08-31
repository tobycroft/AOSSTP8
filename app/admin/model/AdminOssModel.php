<?php

namespace app\admin\model;

use think\Model;

class AdminOssModel extends Model
{
    protected $table = 'ao_oss';

    public function api_list($page, $limit)
    {
        return $this->order('id', 'desc')
            ->paginate($limit, false, ['page' => $page])
            ->toArray();
    }
}