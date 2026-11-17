-- ============================================================
-- v3 AI 项目：新增接口速率限制配额（rate_limit）
--
-- 背景
--   v3 AI 接口需要按项目做固定窗口限流。
--   rate_limit 表示每个窗口（60 秒）内允许的请求次数。
--
-- 兼容
--   rate_limit = 0 表示不限速；存量行 DEFAULT 0 即保持原有行为，
--   无需回填。后台「AI 项目」可逐项目设置。
--
-- 回滚
--   ALTER TABLE `ao_ai` DROP COLUMN `rate_limit`;
-- ============================================================

ALTER TABLE `ao_ai`
    ADD COLUMN `rate_limit` int unsigned NOT NULL DEFAULT 0
    COMMENT 'v3接口每60秒请求上限，0表示不限速' AFTER `model_alias`;
