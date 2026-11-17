-- ============================================================
-- v3 AI 项目：新增对外模型名别名（model_alias）
--
-- 背景
--   返回数据屏蔽上游真实模型名时，原先全局写死别名 aoss。
--   改为每个项目自定义对外返回的 model 值，不同调用方可返回不同名称。
--
-- 兼容
--   model_alias 为空字符串时，服务端回退默认别名 aoss；
--   存量行 DEFAULT '' 即保持原有 aoss 行为，无需回填。
--
-- 回滚
--   ALTER TABLE `ao_ai` DROP COLUMN `model_alias`;
-- ============================================================

ALTER TABLE `ao_ai`
    ADD COLUMN `model_alias` varchar(64) NOT NULL DEFAULT ''
    COMMENT '对外返回的模型名别名，为空则回退默认 aoss' AFTER `name`;
