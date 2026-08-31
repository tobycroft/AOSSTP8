<?php

namespace app\admin\model;

use think\Model;

class AdminSmsWlwxModel extends Model
{
    protected $table = 'ao_sms_wlwx';

    public function api_list($page, $limit)
    {
        return $this->order('id', 'desc')
            ->paginate($limit, false, ['page' => $page])
            ->toArray();
    }
}