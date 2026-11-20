<?php

namespace app\v3\mail\model;

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
class MailTemplateModel extends Model
{
    protected $table = 'ao_mail_template';

    /**
     * 按标识 + 类型取启用中的模板；不存在、停用或类型不符均返回空数组。
     */
    public static function findEnabled(string $code, string $type): array
    {
        $code = trim($code);
        if ($code === '' || !in_array($type, ['captcha', 'marketing'], true)) {
            return [];
        }
        $row = self::field('id, code, name, type, subject, body, is_html')
            ->where('code', $code)
            ->where('type', $type)
            ->where('status', 1)
            ->find();
        return empty($row) ? [] : $row->toArray();
    }
}
