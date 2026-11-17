<?php

namespace app\v3\ai\model;

use think\Model;

/*
CREATE TABLE `ao_ai`（拆分后）:
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) DEFAULT NULL COMMENT '项目名称（人类可读标签，非凭证）',
  `model_alias` varchar(64) DEFAULT '' COMMENT '对外返回的模型名别名，为空回退 aoss',
  `secret` varchar(64) NOT NULL DEFAULT '' COMMENT 'v3 凭证，64 位随机十六进制，同时作为信封加密密钥',
  `agnes_api_id` int unsigned NOT NULL DEFAULT 0 COMMENT '绑定的 agnes API ID',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态',
  `date` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_secret` (`secret`),
  KEY `idx_agnes_api_id` (`agnes_api_id`)

-- agnes 上游配置已独立到 ao_ai_agnes（api_key / base_url / model）。
-- api_find_kid() 在鉴权命中项目后，把绑定 API 的配置并入返回数组，
-- 下游控制器/AiAction 无需改动即可继续从 $proc 取 api_key/base_url/model。
 */

class AiModel extends Model
{
    protected $table = 'ao_ai';

    /**
     * 按凭证定位标识（kid）查找可用项目，并合并其绑定的 agnes API 配置
     *
     * kid = substr(sha256(secret), 0, 8)，与 Aoss\Envelope::kid() 保持一致；
     * 比对在 SQL 侧完成，secret 本身不出现在任何请求参数中。
     * 归一化为小写，避免依赖字段排序规则的大小写敏感性。
     *
     * 拆分后 api_key/base_url/model 不在 ao_ai 上，改为按 agnes_api_id 读取
     * 启用中的 agnes API 并合并进返回数组，保持既有 proc 契约不变。
     * 项目未绑定、绑定 API 不存在或被禁用时返回空数组（等价于"项目不可用"）。
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
            return [];
        }
        $agnes = \think\facade\Db::name('ao_ai_agnes')
            ->field('api_key, base_url, model, status')
            ->where('id', $agnesId)
            ->find();
        if (empty($agnes) || (int)$agnes['status'] !== 1) {
            return [];
        }

        $project['api_key'] = $agnes['api_key'];
        $project['base_url'] = $agnes['base_url'];
        $project['model'] = $agnes['model'];
        return $project;
    }
}
