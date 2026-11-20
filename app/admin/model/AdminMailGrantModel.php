<?php

namespace app\admin\model;

use think\Model;

/**
 * v3 项目邮件服务器授权：ao_v3_project_mail（多对多）
 *
 * 与 AdminV3GrantModel（AI key 授权）同构，只是把 agnes_api_id 换成 mail_server_id。
 */
class AdminMailGrantModel extends Model
{
    protected $table = 'ao_v3_project_mail';

    /**
     * 取某项目的全部授权（含服务器名称与默认标记）
     *
     * @return array [{id, mail_server_id, is_default, name}, ...]
     */
    public static function grantsOf(int $projectId): array
    {
        if ($projectId <= 0) {
            return [];
        }
        return self::alias('g')
            ->join('ao_mail_server s', 's.id = g.mail_server_id', 'LEFT')
            ->field('g.id, g.mail_server_id, g.is_default, s.name')
            ->where('g.project_id', $projectId)
            ->order('g.id', 'asc')
            ->select()
            ->toArray();
    }

    /**
     * 整体替换某项目的授权集合（后台分配弹窗保存）
     *
     * @param array $serverIds 授权的服务器 ID 列表
     * @param int $defaultServerId 默认服务器，须在 $serverIds 内；未指定则取第一个兜底
     */
    public static function replaceGrants(int $projectId, array $serverIds, int $defaultServerId): void
    {
        $serverIds = array_values(array_unique(array_filter(array_map('intval', $serverIds))));
        if ($defaultServerId > 0 && !in_array($defaultServerId, $serverIds, true)) {
            $defaultServerId = 0;
        }
        if ($defaultServerId <= 0 && !empty($serverIds)) {
            $defaultServerId = $serverIds[0];
        }

        \think\facade\Db::transaction(function () use ($projectId, $serverIds, $defaultServerId) {
            self::where('project_id', $projectId)->delete();
            foreach ($serverIds as $serverId) {
                self::create([
                    'project_id'     => $projectId,
                    'mail_server_id' => $serverId,
                    'is_default'     => $serverId === $defaultServerId ? 1 : 0,
                ]);
            }
        });
    }
}
