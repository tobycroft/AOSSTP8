<?php

namespace app\index\model;

use think\Model;

class HookModel extends Model
{
    protected $table = 'ao_hook';

    /**
     * 按用户分页查询 Hook
     */
    public function api_list_by_uid(int $uid, string $tag = '', int $page = 1, int $limit = 15): array
    {
        $query = $this->where('uid', '=', $uid);
        if (!empty($tag)) {
            $query->where('tag', 'like', "%{$tag}%");
        }
        return $query->order('id', 'desc')
            ->paginate($limit, false, ['page' => $page])
            ->toArray();
    }

    /**
     * 按用户隔离查找单条 Hook
     */
    public function api_find_uid(int $id, int $uid)
    {
        return $this->where('id', '=', $id)
            ->where('uid', '=', $uid)
            ->findOrEmpty();
    }
}
