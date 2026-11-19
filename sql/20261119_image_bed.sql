-- 门户图床：用户图片存储表；并移除原门户控制台专用的调用日志表 ao_ak_log
-- 执行方式：mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < sql/20261119_image_bed.sql
-- 说明：ao_ak_log 原仅由门户控制台「调用日志」与已废弃的 v2 ProjectModel 调用日志写入，
--       移除控制台后已无任何读写，故删除；
--       ao_ak / ao_hook / ao_login_log / ao_user 等仍被登录注册或其它模块使用，予以保留。

CREATE TABLE IF NOT EXISTS `ao_image_bed` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `uid` int unsigned NOT NULL DEFAULT '0' COMMENT '所属门户用户ID',
  `name` varchar(255) NOT NULL DEFAULT '' COMMENT '原始文件名',
  `path` varchar(255) NOT NULL DEFAULT '' COMMENT '相对存储路径',
  `url` varchar(512) NOT NULL DEFAULT '' COMMENT '访问URL',
  `mime` varchar(128) NOT NULL DEFAULT '',
  `size` int unsigned NOT NULL DEFAULT '0',
  `ext` varchar(32) NOT NULL DEFAULT '',
  `md5` varchar(64) NOT NULL DEFAULT '',
  `width` int unsigned NOT NULL DEFAULT '0',
  `height` int unsigned NOT NULL DEFAULT '0',
  `ip` varchar(45) NOT NULL DEFAULT '',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_uid` (`uid`),
  KEY `idx_uid_md5` (`uid`,`md5`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='门户图床';

DROP TABLE IF EXISTS `ao_ak_log`;

-- 为图床注册一条本地存储项目，统一图片访问域名（与其它 local 项目一致，文档根指向 ./upload）
INSERT INTO `ao_oss` (`type`, `main_type`, `name`, `code`, `token`, `oss_type`, `oss_tag`, `sms_type`, `sms_tag`, `sms_limit`, `url`, `ext`, `size`, `status`)
SELECT 'local', 'local', 'imagebed', '', 'imagebed', 'none', 'default', 'none', 'none', 20, 'https://image.tuuz.cc:433', 'jpg,jpeg,png,gif,bmp,webp', 10240, 1
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `ao_oss` WHERE `token` = 'imagebed');