<?php

namespace app\v3\ai\model;

use think\Model;

/*
CREATE TABLE `ao_ai` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) DEFAULT NULL COMMENT '项目名称（人类可读标签，非凭证）',
  `secret` varchar(64) NOT NULL DEFAULT '' COMMENT 'v3 凭证，64 位随机十六进制，同时作为信封加密密钥',
  `api_key` varchar(255) DEFAULT NULL COMMENT 'API密钥',
  `base_url` varchar(255) DEFAULT 'https://apihub.agnes-ai.com/v1' COMMENT 'API地址',
  `model` varchar(64) DEFAULT 'agnes-3.0-flash' COMMENT '模型名称',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态',
  `date` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_secret` (`secret`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI接口配置';
 */

class AiModel extends Model
{
    protected $table = 'ao_ai';

    /**
     * 按凭证定位标识（kid）查找可用项目
     *
     * kid = substr(sha256(secret), 0, 8)，与 Aoss\Envelope::kid() 保持一致；
     * 比对在 SQL 侧完成，secret 本身不出现在任何请求参数中。
     * 归一化为小写，避免依赖字段排序规则的大小写敏感性。
     *
     * @param string $kid 8 位小写十六进制定位标识
     * @return array
     */
    public static function api_find_kid(string $kid): array
    {
        if (preg_match('/^[0-9a-f]{8}$/', $kid) !== 1) {
            return [];
        }
        return self::where('status', 1)
            ->whereRaw('LOWER(LEFT(SHA2(`secret`, 256), 8)) = ?', [$kid])
            ->findOrEmpty()
            ->toArray();
    }
}
