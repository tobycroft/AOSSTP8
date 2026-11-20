<?php

namespace app\admin\model;

use think\Model;

/*
CREATE TABLE `ao_mail_template` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) NOT NULL COMMENT '模板标识（API 通过 template 引用）',
  `name` varchar(64) NOT NULL COMMENT '模板名称（后台展示）',
  `type` varchar(16) NOT NULL COMMENT '类型：captcha 验证码 / marketing 营销',
  `subject` varchar(255) NOT NULL COMMENT '主题模板，可含 {变量名} 占位符',
  `body` text NOT NULL COMMENT '正文模板，可含 {变量名} 占位符',
  `is_html` tinyint(1) NOT NULL DEFAULT 1 COMMENT '正文是否 HTML：1是 0否',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态：1启用 0停用',
  `date` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_mail_template_code` (`code`),
  KEY `idx_mail_template_type` (`type`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='v3 邮件内容模板（验证码/营销统一主题与正文）';
*/
class AdminMailTemplateModel extends Model
{
    protected $table = 'ao_mail_template';

    /** 模板标识是否已被占用（排除自身） */
    public static function codeExists(string $code, int $excludeId = 0): bool
    {
        if ($code === '') {
            return false;
        }
        $query = self::where('code', $code);
        if ($excludeId > 0) {
            $query->where('id', '<>', $excludeId);
        }
        return $query->count() > 0;
    }

    /** 模板被哪些类型使用（仅展示用途，当前模板为全局，不按项目隔离） */
    public static function typeLabel(string $type): string
    {
        return $type === 'captcha' ? '验证码' : ($type === 'marketing' ? '营销' : $type);
    }
}
