<?php

namespace app\index\model;

use think\Model;

class CallLogModel extends Model
{
    protected $table = 'ao_ak_log';

    /**
     * 记录一次 AK 接口调用（在 v2 ProjectModel::api_find_token 鉴权成功后调用）
     */
    public static function addLog(array $project): void
    {
        self::create([
            'uid' => intval($project['uid'] ?? 0),
            'appid' => intval($project['appid'] ?? 0),
            'project' => strval($project['project'] ?? ''),
            'path' => strval(request()->pathinfo()),
            'method' => request()->method(),
            'ip' => request()->ip(),
            'date' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 按用户分页查询调用日志（可按项目过滤）
     */
    public function api_list_by_uid(int $uid, int $appid = 0, int $page = 1, int $limit = 15): array
    {
        $query = $this->where('uid', '=', $uid);
        if ($appid > 0) {
            $query->where('appid', '=', $appid);
        }
        return $query->order('id', 'desc')
            ->paginate($limit, false, ['page' => $page])
            ->toArray();
    }
}
