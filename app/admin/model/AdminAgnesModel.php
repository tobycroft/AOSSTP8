<?php

namespace app\admin\model;

use think\Model;

/*
CREATE TABLE `ao_ai_agnes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) DEFAULT NULL COMMENT 'API 名称（人类可读标签）',
  `api_key` varchar(255) DEFAULT NULL COMMENT 'Agnes API 密钥',
  `base_url` varchar(255) DEFAULT 'https://apihub.agnes-ai.com/v1' COMMENT 'API 地址',
  `model` varchar(64) DEFAULT 'agnes-3.0-flash' COMMENT '模型名称',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态：1启用 0禁用',
  `date` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Agnes API 配置';
 */

class AdminAgnesModel extends Model
{
    protected $table = 'ao_ai_agnes';

    public function api_list($page, $limit)
    {
        return $this->order('id', 'desc')
            ->paginate($limit, false, ['page' => $page])
            ->toArray();
    }

    /**
     * 被多少项目绑定：删除前用于提示，避免删掉仍被引用的 API
     */
    public static function boundCount(int $id): int
    {
        if ($id <= 0) {
            return 0;
        }
        return \think\facade\Db::name('ao_v3_project_agnes')->where('agnes_api_id', $id)->count();
    }

    /**
     * 供后台表单下拉选择的 Agnes API 列表（id + 名称，避免暴露密钥）
     *
     * @return array [{id, name}, ...]
     */
    public static function agnesOptions(): array
    {
        return \think\facade\Db::name('ao_ai_agnes')
            ->field('id, name')
            ->order('id', 'desc')
            ->select()
            ->toArray();
    }

    /**
     * 按 agnes_api_id 取出上游配置，供在线测试复用 AiAction。
     * 只取启用中的 API；禁用或已删除则返回空配置，由调用方提示。
     *
     * @param int $agnesApiId
     * @return array ['base_url','model','api_key'] 或 []
     */
    public static function agnesConfig(int $agnesApiId): array
    {
        if ($agnesApiId <= 0) {
            return [];
        }
        $row = \think\facade\Db::name('ao_ai_agnes')
            ->field('api_key, base_url, model, status')
            ->where('id', $agnesApiId)
            ->find();
        if (empty($row) || (int)$row['status'] !== 1) {
            return [];
        }
        return [
            'api_key' => $row['api_key'],
            'base_url' => $row['base_url'],
            'model' => $row['model'],
        ];
    }
}
