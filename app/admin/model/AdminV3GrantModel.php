<?php

namespace app\admin\model;

use think\Model;

/*
CREATE TABLE `ao_v3_project_agnes`:
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `project_id` int unsigned NOT NULL COMMENT 'v3 项目 ID（ao_v3_project.id）',
  `agnes_api_id` int unsigned NOT NULL COMMENT 'agnes API ID（ao_ai_agnes.id）',
  `is_default` tinyint(1) DEFAULT 0 COMMENT '默认使用的 AI key（AI 请求取此 key）',
  `date` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_project_agnes` (`project_id`, `agnes_api_id`),
  KEY `idx_agnes_api_id` (`agnes_api_id`)
 */

class AdminV3GrantModel extends Model
{
    protected $table = 'ao_v3_project_agnes';

    /**
     * 取某项目的全部授权（含 agnes 名称与默认标记）
     *
     * @param int $projectId
     * @return array [{id, agnes_api_id, is_default, name}, ...]
     */
    public static function grantsOf(int $projectId): array
    {
        if ($projectId <= 0) {
            return [];
        }
        return self::alias('g')
            ->join('ao_ai_agnes a', 'a.id = g.agnes_api_id', 'LEFT')
            ->field('g.id, g.agnes_api_id, g.is_default, a.name')
            ->where('g.project_id', $projectId)
            ->order('g.id', 'asc')
            ->select()
            ->toArray();
    }

    /**
     * 整体替换某项目的授权集合（后台分配弹窗保存）
     *
     * @param int $projectId
     * @param array $agnesIds 授权的 agnes API ID 列表
     * @param int $defaultAgnesId 默认 key，须在 $agnesIds 内
     * @return void
     */
    public static function replaceGrants(int $projectId, array $agnesIds, int $defaultAgnesId): void
    {
        $agnesIds = array_values(array_unique(array_filter(array_map('intval', $agnesIds))));
        if ($defaultAgnesId > 0 && !in_array($defaultAgnesId, $agnesIds, true)) {
            $defaultAgnesId = 0;
        }
        // 无默认时取第一个授权兜底，保证 AI 请求总有 key 可用
        if ($defaultAgnesId <= 0 && !empty($agnesIds)) {
            $defaultAgnesId = $agnesIds[0];
        }

        \think\facade\Db::transaction(function () use ($projectId, $agnesIds, $defaultAgnesId) {
            self::where('project_id', $projectId)->delete();
            foreach ($agnesIds as $agnesId) {
                self::create([
                    'project_id' => $projectId,
                    'agnes_api_id' => $agnesId,
                    'is_default' => $agnesId === $defaultAgnesId ? 1 : 0,
                ]);
            }
        });
    }
}
