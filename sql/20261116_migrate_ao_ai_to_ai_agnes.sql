-- ============================================================
-- 拆分 ao_ai：存量数据 1:1 迁移到 ao_ai_agnes，并回填 agnes_api_id
--
-- 前置条件
--   1) 已执行 20261116_create_ao_ai_agnes.sql
--   2) 已部署与本脚本配套的新版代码（后台 agnes 管理 / 项目绑定 / v3 合并 proc）
--      否则删除旧列后，旧版代码读取 api_key/base_url/model 会报错
--
-- 迁移策略
--   每条 ao_ai 按其现有 api_key/base_url/model 生成一条 ao_ai_agnes 记录，
--   再把新记录 id 回填到 ao_ai.agnes_api_id。1:1 迁移，不丢密钥、不丢配置。
--   迁移后可在后台把多个项目改绑到同一个 agnes API，实现密钥复用。
--
-- 幂等性
--   用临时列 tmp_ai_id 记录来源 ao_ai.id，仅迁移 agnes_api_id = 0 的项目，
--   重复执行不会重复建行；脚本末尾会删除临时列。
--
-- 回滚
--   -- 删除本次迁移出来的 agnes API（确认没有被项目绑定后再删）
--   DELETE FROM `ao_ai_agnes`;
--   -- 恢复 ao_ai 旧列并从残留 agnes API 回填（若 agnes 表已删则手工补回密钥）
--   ALTER TABLE `ao_ai`
--     ADD COLUMN `api_key` varchar(255) DEFAULT NULL COMMENT 'API密钥',
--     ADD COLUMN `base_url` varchar(255) DEFAULT 'https://apihub.agnes-ai.com/v1' COMMENT 'API地址',
--     ADD COLUMN `model` varchar(64) DEFAULT 'agnes-3.0-flash' COMMENT '模型名称';
--   UPDATE `ao_ai` a LEFT JOIN `ao_ai_agnes` g ON a.`agnes_api_id` = g.`id`
--     SET a.`api_key` = g.`api_key`, a.`base_url` = g.`base_url`, a.`model` = g.`model`;
-- ============================================================

-- 1) 临时列：记录每条 agnes API 的来源项目，便于精确回填外键
ALTER TABLE `ao_ai_agnes`
    ADD COLUMN `tmp_ai_id` int unsigned DEFAULT NULL COMMENT '迁移用：来源 ao_ai.id';

-- 2) 1:1 生成 agnes API 行；名称沿用项目名，可读且不丢原值
INSERT INTO `ao_ai_agnes` (`name`, `api_key`, `base_url`, `model`, `status`, `date`, `tmp_ai_id`)
SELECT `name`, `api_key`, `base_url`, `model`, `status`, `date`, `id`
FROM `ao_ai`
WHERE `agnes_api_id` = 0;

-- 3) 回填外键：来源 id -> 新 agnes API id
UPDATE `ao_ai` a
    INNER JOIN `ao_ai_agnes` g ON g.`tmp_ai_id` = a.`id`
SET a.`agnes_api_id` = g.`id`
WHERE a.`agnes_api_id` = 0;

-- 4) 清理临时列
ALTER TABLE `ao_ai_agnes` DROP COLUMN `tmp_ai_id`;

-- 5) 删除 ao_ai 上已迁走的旧列（仅在确认新版代码已上线后执行）
ALTER TABLE `ao_ai`
    DROP COLUMN `api_key`,
    DROP COLUMN `base_url`,
    DROP COLUMN `model`;
