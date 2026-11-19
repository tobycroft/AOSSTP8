<?php

namespace app\v3\project\model;

use think\Model;

/*
CREATE TABLE `ao_v3_project`:
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) DEFAULT NULL COMMENT '项目名称（人类可读标签，非凭证）',
  `rate_limit` int unsigned DEFAULT 0 COMMENT 'v3接口每60秒请求上限，0表示不限速',
  `model_alias` varchar(64) DEFAULT '' COMMENT '对外返回的模型名别名（AI 应用），为空回退 aoss',
  `agnes_api_id` int unsigned NOT NULL DEFAULT 0 COMMENT '绑定的 agnes API ID（AI 应用），0 未绑定',
  `secret` varchar(64) NOT NULL DEFAULT '' COMMENT 'v3 凭证，64 位随机十六进制，同时作为信封加密密钥',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态',
  `date` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_secret` (`secret`),
  KEY `idx_agnes_api_id` (`agnes_api_id`)

v3 项目为应用无关的通用凭证：一个项目（secret）可使用 v3 下全部应用。
AI 专属配置（agnes 绑定、模型别名）直接挂在项目上；
原 ao_ai 已全量迁入本表，ao_ai 仅作历史备份保留，不再参与鉴权。
 */

class V3ProjectModel extends Model
{
    protected $table = 'ao_v3_project';

    /**
     * 按凭证定位标识（kid）查找可用项目，并合并其绑定的 agnes API 配置
     *
     * kid = substr(sha256(secret), 0, 8)，与 Aoss\Envelope::kid() 保持一致；
     * 比对在 SQL 侧完成，secret 本身不出现在任何请求参数中。
     * 归一化为小写，避免依赖字段排序规则的大小写敏感性。
     *
     * 项目绑定了启用中的 agnes API 时，把 api_key/base_url/model 并入返回数组
     * （AI 控制器/AiAction 继续从 $proc 取这三个键，契约与迁移前一致）；
     * 未绑定、绑定 API 不存在或被禁用时仅返回项目本身，AI 应用自行据此判定不可用。
     *
     * @param string $kid 8 位小写十六进制定位标识
     * @return array
     */
    public static function api_find_kid(string $kid): array
    {
        if (preg_match('/^[0-9a-f]{8}$/', $kid) !== 1) {
            return [];
        }
        $project = self::where('status', 1)
            ->whereRaw('LOWER(LEFT(SHA2(`secret`, 256), 8)) = ?', [$kid])
            ->findOrEmpty()
            ->toArray();
        if (empty($project)) {
            return [];
        }

        $agnesId = (int)($project['agnes_api_id'] ?? 0);
        if ($agnesId <= 0) {
            return $project;
        }
        $agnes = \think\facade\Db::name('ao_ai_agnes')
            ->field('api_key, base_url, model, status')
            ->where('id', $agnesId)
            ->find();
        if (empty($agnes) || (int)$agnes['status'] !== 1) {
            return $project;
        }

        $project['api_key'] = $agnes['api_key'];
        $project['base_url'] = $agnes['base_url'];
        $project['model'] = $agnes['model'];
        return $project;
    }
}
