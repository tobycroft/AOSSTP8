<?php

namespace app\admin\model;

use think\Model;

/*
CREATE TABLE `ao_mail_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `kid` varchar(16) DEFAULT NULL COMMENT '调用方项目 kid',
  `project_id` int unsigned DEFAULT 0 COMMENT 'v3 项目 ID',
  `server_id` int unsigned DEFAULT 0 COMMENT '使用的邮件服务器 ID',
  `server_name` varchar(64) DEFAULT NULL COMMENT '邮件服务器名称快照',
  `to_addr` varchar(255) DEFAULT NULL COMMENT '收件人',
  `cc_addr` varchar(255) DEFAULT NULL COMMENT '抄送',
  `subject` varchar(255) DEFAULT NULL COMMENT '主题',
  `mail_type` varchar(16) DEFAULT NULL COMMENT '内容类型：captcha/marketing/custom',
  `template_code` varchar(64) DEFAULT NULL COMMENT '模板标识（模板类型时记录）',
  `success` tinyint(1) DEFAULT 0 COMMENT '是否成功：1成功 0失败',
  `error` varchar(1000) DEFAULT NULL COMMENT '错误信息',
  `date` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_project_id` (`project_id`),
  KEY `idx_kid` (`kid`),
  KEY `idx_date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='邮件发送日志';
*/
class AdminMailLogModel extends Model
{
    protected $table = 'ao_mail_log';
}
