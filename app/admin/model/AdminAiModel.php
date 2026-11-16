<?php

namespace app\admin\model;

use think\Model;

class AdminAiModel extends Model
{
    protected $table = 'ao_ai';

    public function api_list($page, $limit)
    {
        return $this->order('id', 'desc')
            ->paginate($limit, false, ['page' => $page])
            ->toArray();
    }
}