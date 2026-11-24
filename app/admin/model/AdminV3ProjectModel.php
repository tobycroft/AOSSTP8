<?php

namespace app\admin\model;

use think\Model;

/*
CREATE TABLE `ao_v3_project`:
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) DEFAULT NULL COMMENT '项目名称（人类可读标签，非凭证）',
  `rate_limit` int unsigned DEFAULT 0 COMMENT 'v3接口每60秒请求上限，0表示不限速',
  `model_alias` varchar(64) DEFAULT '' COMMENT '对外返回的模型名别名（AI 应用），为空回退 aoss',
  `secret` varchar(64) NOT NULL DEFAULT '' COMMENT 'v3 凭证，64 位随机十六进制，同时作为信封加密密钥',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态',
  `date` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_secret` (`secret`)

V3 项目只维护鉴权信息，不承载存储等应用配置。
Bucket 配置在独立表 ao_v3_storage（后台「存储管理 V3」），项目与 Bucket 的一对多关联在 ao_v3_storage_project（后台「存储项目」）。
 */
class AdminV3ProjectModel extends Model
{
    protected $table = 'ao_v3_project';
}
