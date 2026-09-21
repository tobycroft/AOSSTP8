<?php

namespace app\index\model;

use think\Model;

class ProjectModel extends Model
{
    protected $table = 'ao_ak';

    public function api_list_by_uid($uid, string $search = '', int $page = 1, int $limit = 15)
    {
        $query = $this->where('uid', '=', $uid)->order('appid', 'desc');
        if ($search !== '') {
            $query->where('project', 'like', '%' . $search . '%');
        }
        return $query->paginate($limit, false, ['page' => $page])->toArray();
    }

    public function api_find_uid($appid, $uid)
    {
        return $this->where('appid', '=', $appid)
            ->where('uid', '=', $uid)
            ->findOrEmpty();
    }
}
