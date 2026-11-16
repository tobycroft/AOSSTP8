<?php

namespace app\v3\ai\model;

use think\Model;

/*
CREATE TABLE `ao_ai` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `project` varchar(64) DEFAULT NULL COMMENT '项目token',
  `api_key` varchar(255) DEFAULT NULL COMMENT 'API密钥',
  `base_url` varchar(255) DEFAULT 'https://apihub.agnes-ai.com/v1' COMMENT 'API地址',
  `model` varchar(64) DEFAULT 'agnes-3.0-flash' COMMENT '模型名称',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态',
  `date` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_project` (`project`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI接口配置';
 */

class AiModel extends Model
{
    protected $table = 'ao_ai';

    public static function api_find_token($token): array
    {
        return self::where('project', $token)->where('status', 1)->findOrEmpty()->toArray();
    }
}