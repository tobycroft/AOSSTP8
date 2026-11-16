-- 门户登录风控：登录失败状态表
-- 执行方式：mysql -h <host> -u <user> -p <database> < sql/20261116_create_ao_login_fail.sql
-- 说明：每个客户端 IP 一行，记录窗口期（2 小时）内的连续失败次数；
--       登录成功即删除该行，故表数据量约为“失败中的 IP 数”，不会持续增长。
--       未执行本脚本时，LoginGuard 会降级为不强制验证码并写 warning 日志，不影响登录。

CREATE TABLE IF NOT EXISTS `ao_login_fail` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ip` varchar(64) NOT NULL DEFAULT '' COMMENT '客户端 IP',
  `fail_count` int unsigned NOT NULL DEFAULT '0' COMMENT '窗口期内失败次数',
  `last_fail` datetime NOT NULL DEFAULT '1970-01-01 00:00:00' COMMENT '最近一次失败时间',
  `change_date` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `date` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ip` (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='门户登录失败状态';
