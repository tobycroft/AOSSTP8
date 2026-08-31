<?php

namespace app\admin\model;

use think\Model;

class AdminSmsLcModel extends Model
{
    protected $table = 'ao_sms_lc';

    public function api_list($page, $limit)
    {
        return $this->order('id', 'desc')
            ->paginate($limit, false, ['page' => $page])
            ->toArray();
    }
}