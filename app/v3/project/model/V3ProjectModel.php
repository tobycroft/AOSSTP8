<?php

namespace app\v3\project\model;

use think\Model;

/*
CREATE TABLE `ao_v3_project`:
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) DEFAULT NULL COMMENT '项目名称（人类可读标签，非凭证）',
  `rate_limit` int unsigned DEFAULT 0 COMMENT 'v3接口每60秒请求上限，0表示不限速',
  `secret` varchar(64) NOT NULL DEFAULT '' COMMENT 'v3 凭证，64 位随机十六进制，同时作为信封加密密钥',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态',
  `date` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_secret` (`secret`)

v3 项目为应用无关的通用凭证：一个项目（secret）可使用 v3 下全部应用
（excel 等），与 ao_ai（AI 专属，绑定 agnes API）相互独立、互不影响。
 */

class V3ProjectModel extends Model
{
    protected $table = 'ao_v3_project';

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
