-- ============================================================
-- 拆分 ao_ai：新建 ao_ai_agnes（agnes API 密钥独立存储）
--
-- 背景
--   原 ao_ai 每行同时承载两类职责：
--     1) v3 调用方项目：name / secret（凭证）/ status
--     2) agnes 上游配置：api_key / base_url / model
--   一个项目只能配一把密钥，无法让多个项目共用同一把 agnes API。
--   现将 agnes 上游配置独立成 ao_ai_agnes，ao_ai 只保留项目与凭证，
--   通过 agnes_api_id 绑定某条 agnes API，实现多对一复用。
--
-- 本脚本只做结构准备（向后兼容）：
--   1) 新建 ao_ai_agnes 表
--   2) ao_ai 增加 agnes_api_id 列与索引
--   旧代码仍可继续读取 ao_ai 上的 api_key/base_url/model，不会中断。
--   数据迁移与旧列删除见 20261116_migrate_ao_ai_to_agnes_api.sql。
--
-- 回滚
--   DROP TABLE IF EXISTS `ao_ai_agnes`;
--   ALTER TABLE `ao_ai` DROP INDEX `idx_agnes_api_id`, DROP COLUMN `agnes_api_id`;
-- ============================================================

CREATE TABLE IF NOT EXISTS `ao_ai_agnes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) DEFAULT NULL COMMENT 'API 名称（人类可读标签）',
  `api_key` varchar(255) DEFAULT NULL COMMENT 'agnes API 密钥',
  `base_url` varchar(255) DEFAULT 'https://apihub.agnes-ai.com/v1' COMMENT 'API 地址',
  `model` varchar(64) DEFAULT 'agnes-3.0-flash' COMMENT '模型名称',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态：1启用 0禁用',
  `date` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='agnes API 配置';

ALTER TABLE `ao_ai`
    ADD COLUMN `agnes_api_id` int unsigned NOT NULL DEFAULT 0
    COMMENT '绑定的 agnes API ID' AFTER `secret`;

ALTER TABLE `ao_ai`
    ADD INDEX `idx_agnes_api_id` (`agnes_api_id`);
