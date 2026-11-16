-- ============================================================
-- ao_ai：project 列更名为 secret，凭证统一为 64 位随机十六进制
--
-- 背景
--   原 project 列同时承担两个互相冲突的角色：
--     1) 项目标识：需要人类可读、可出现在后台列表与工单中
--     2) 凭证密钥：需要高熵不可猜
--   5~8 位的可读值熵不足（26^5 ≈ 1188 万），凭证信封的密文可被离线枚举还原
--   （实测单机 <1 秒还原）。故取消「项目 token」概念：
--     name   作为人类可读标签
--     secret 作为唯一凭证，由服务端签发 64 位随机十六进制（32 字节熵）
--
-- 影响
--   执行后所有现有 v3 调用方立即失效，需在后台「AI接口配置」重新获取 secret。
--
-- 回滚
--   ALTER TABLE ao_ai CHANGE COLUMN `secret` `project` varchar(64) DEFAULT NULL COMMENT '项目token';
--   ALTER TABLE ao_ai DROP INDEX `idx_secret`, ADD INDEX `idx_project` (`project`);
-- ============================================================

ALTER TABLE `ao_ai`
    CHANGE COLUMN `project` `secret` varchar(64) NOT NULL DEFAULT ''
    COMMENT 'v3 凭证，64 位随机十六进制，同时作为信封加密密钥';

ALTER TABLE `ao_ai`
    DROP INDEX `idx_project`,
    ADD INDEX `idx_secret` (`secret`);

-- 存量行一律重新签发：旧的可读值即为本次要淘汰的弱凭证，保留它等于没修
-- 注：MySQL 9.x 已移除 MD5() 与 SHA1()（FIPS 合规），此处用 SHA2 生成 64 位十六进制
UPDATE `ao_ai`
SET `secret` = LOWER(SHA2(CONCAT(UUID(), RAND(), UUID()), 256))
WHERE `secret` = '' OR CHAR_LENGTH(`secret`) <> 64;
