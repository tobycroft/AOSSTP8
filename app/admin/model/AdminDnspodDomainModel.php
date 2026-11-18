<?php

namespace app\admin\model;

use think\Model;

/*
CREATE TABLE `ao_dnspod_domain` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `dnspod_id` int unsigned NOT NULL DEFAULT 0 COMMENT '来源 DNSPod 配置 ID (ao_dnspod.id)',
  `domain_id` int unsigned NOT NULL DEFAULT 0 COMMENT 'DNSPod 域名 ID',
  `name` varchar(255) NOT NULL DEFAULT '' COMMENT '域名',
  `punycode` varchar(255) NOT NULL DEFAULT '' COMMENT 'punycode 编码',
  `status` varchar(32) NOT NULL DEFAULT '' COMMENT '域名状态 (enable/disable)',
  `records` int unsigned NOT NULL DEFAULT 0 COMMENT '记录数',
  `grade` varchar(32) NOT NULL DEFAULT '' COMMENT '域名等级',
  `date` datetime DEFAULT CURRENT_TIMESTAMP COMMENT '同步时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name` (`name`),
  KEY `idx_dnspod_id` (`dnspod_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='DNSPod 域名缓存';
 */

class AdminDnspodDomainModel extends Model
{
    protected $table = 'ao_dnspod_domain';
}