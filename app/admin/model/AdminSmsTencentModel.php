<?php

namespace app\admin\model;

use think\Model;

class AdminSmsTencentModel extends Model
{
    protected $table = 'ao_sms_tencent';

    public function api_list($page, $limit)
    {
        return $this->order('id', 'desc')
            ->paginate($limit, false, ['page' => $page])
            ->toArray();
    }
}