<?php

namespace app\v2\project\model;

use app\index\model\CallLogModel;
use think\Model;

class ProjectModel extends Model
{
    protected $table = 'ao_project';

    public function api_find_token($token)
    {
        $proc = $this->where('open_token', '=', $token)
            ->where('is_avail', '=', 1)
            ->findOrEmpty();
        // 记录 AK 调用日志（uid=0 为管理员项目，门户控制台不展示）
        if (!$proc->isEmpty()) {
            CallLogModel::addLog($proc->toArray());
        }
        return $proc;
    }

    public function api_find_appid($appid)
    {
        return $this->where('appid', '=', $appid)
            ->where('is_avail', '=', 1)
            ->findOrEmpty();
    }
}