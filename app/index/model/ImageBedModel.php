<?php

namespace app\index\model;

use think\Model;

class ImageBedModel extends Model
{
    protected $table = 'ao_image_bed';

    /**
     * 按用户分页查询图片
     */
    public function api_list_by_uid(int $uid, int $page = 1, int $limit = 24): array
    {
        return $this->where('uid', '=', $uid)
            ->order('id', 'desc')
            ->paginate($limit, false, ['page' => $page])->toArray();
    }
}