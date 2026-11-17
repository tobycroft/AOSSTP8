<?php

namespace app\admin\model;

use think\Model;

/*
CREATE TABLE `ao_ai`（拆分后）:
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) DEFAULT NULL COMMENT '项目名称（人类可读标签，非凭证）',
  `model_alias` varchar(64) DEFAULT '' COMMENT '对外返回的模型名别名，为空回退 aoss',
  `rate_limit` int unsigned DEFAULT 0 COMMENT 'v3接口每60秒请求上限，0表示不限速',
  `secret` varchar(64) NOT NULL DEFAULT '' COMMENT 'v3 凭证，64 位随机十六进制，同时作为信封加密密钥',
  `agnes_api_id` int unsigned NOT NULL DEFAULT 0 COMMENT '绑定的 agnes API ID',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态',
  `date` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_secret` (`secret`),
  KEY `idx_agnes_api_id` (`agnes_api_id`)
 */

class AdminAiModel extends Model
{
    protected $table = 'ao_ai';

    public function api_list($page, $limit)
    {
        return $this->order('id', 'desc')
            ->paginate($limit, false, ['page' => $page])
            ->toArray();
    }

    /**
     * 供后台表单下拉选择的 agnes API 列表（id + 名称，避免暴露密钥）
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
