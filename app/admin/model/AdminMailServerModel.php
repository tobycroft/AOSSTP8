<?php

namespace app\admin\model;

use think\Model;

/*
CREATE TABLE `ao_mail_server` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL COMMENT '服务器名称（人类可读标签）',
  `host` varchar(255) NOT NULL COMMENT 'SMTP 主机',
  `port` int unsigned NOT NULL DEFAULT 465 COMMENT 'SMTP 端口',
  `encryption` varchar(16) NOT NULL DEFAULT 'ssl' COMMENT '加密方式：ssl / tls / none',
  `username` varchar(255) DEFAULT NULL COMMENT 'SMTP 账号',
  `password` varchar(255) DEFAULT NULL COMMENT 'SMTP 密码/授权码',
  `from_addr` varchar(255) DEFAULT NULL COMMENT '默认发件人地址',
  `from_name` varchar(64) DEFAULT NULL COMMENT '默认发件人显示名',
  `status` tinyint(1) DEFAULT 1 COMMENT '状态：1启用 0禁用',
  `date` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='邮件服务器(SMTP)配置';

CREATE TABLE `ao_v3_project_mail` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `project_id` int unsigned NOT NULL COMMENT 'v3 项目 ID（ao_v3_project.id）',
  `mail_server_id` int unsigned NOT NULL COMMENT '邮件服务器 ID（ao_mail_server.id）',
  `is_default` tinyint(1) DEFAULT 0 COMMENT '默认服务器（邮件请求取此服务器）',
  `date` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_project_mail` (`project_id`, `mail_server_id`),
  KEY `idx_mail_server_id` (`mail_server_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='v3 项目邮件服务器授权';
*/
class AdminMailServerModel extends Model
{
    protected $table = 'ao_mail_server';

    /**
     * 被多少项目授权：删除前提示，避免删掉仍被引用的服务器
     */
    public static function boundCount(int $id): int
    {
        if ($id <= 0) {
            return 0;
        }
        return \think\facade\Db::name('ao_v3_project_mail')
            ->where('mail_server_id', $id)
            ->count();
    }

    /**
     * 供后台表单下拉选择的服务器列表（id + 名称，不暴露密码）
     */
    public static function serverOptions(): array
    {
        return self::field('id, name')->order('id', 'desc')->select()->toArray();
    }

    /**
     * 取启用中的服务器完整配置，供在线测试发信复用
     */
    public static function serverConfig(int $serverId): array
    {
        if ($serverId <= 0) {
            return [];
        }
        $row = self::field('id, name, host, port, encryption, username, password, from_addr, from_name, status')
            ->where('id', $serverId)
            ->find();
        if (empty($row) || (int)$row['status'] !== 1) {
            return [];
        }
        return $row->toArray();
    }
}
