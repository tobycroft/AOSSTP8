-- 门户图床：复用 ao_attachment（新增 tag 列存门户用户ID），并清理门户控制台废弃表
-- 执行方式：mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < sql/20261119_imagebed_attachment.sql
-- 说明：
--   1. ao_attachment 增加 tag 列，门户图床上传时写入 tag=用户ID，列表/删除按 token=imagebed + tag 过滤；
--      其它项目上传 tag 为空，行为不变。
--   2. 删除门户控制台专用且已无任何读写的 ao_ak_log。

-- 1. ao_attachment 增加 tag 列（幂等）
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ao_attachment' AND COLUMN_NAME = 'tag');
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `ao_attachment` ADD COLUMN `tag` varchar(64) NOT NULL DEFAULT '''' COMMENT ''标签/归属'' AFTER `token`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 为 tag 建索引，加速图床按用户查询
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ao_attachment' AND INDEX_NAME = 'idx_token_tag');
SET @sql := IF(@idx_exists = 0,
  'ALTER TABLE `ao_attachment` ADD INDEX `idx_token_tag` (`token`, `tag`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. 清理门户控制台及上一版图床方案的废弃表
DROP TABLE IF EXISTS `ao_ak_log`;
DROP TABLE IF EXISTS `ao_image_bed`;

-- 3. 确保图床存储项目存在（幂等，不存在时初始化）
INSERT INTO `ao_oss` (`type`, `main_type`, `name`, `code`, `token`, `oss_type`, `oss_tag`, `sms_type`, `sms_tag`, `sms_limit`, `url`, `ext`, `size`, `status`)
SELECT 'local', 'local', 'imagebed', '', 'imagebed', 'none', 'default', 'none', 'none', 20, 'https://image.tuuz.cc:433', 'jpg,jpeg,png,gif,bmp,webp', 10240, 1
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `ao_oss` WHERE `token` = 'imagebed');