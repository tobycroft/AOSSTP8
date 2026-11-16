-- 后台登录风控：admin_login_log 增加 IP 索引
-- 用途：登录页与登录接口按 IP 查询"该 IP 最近一次登录结果"（LoginGuard::needCaptcha），
--       避免 WHERE ip = ? ORDER BY id DESC 触发全表扫描（账号维度已有 idx_username）。
-- 说明：纯性能优化项，未执行时功能仍可正常工作。

ALTER TABLE `admin_login_log` ADD INDEX `idx_ip` (`ip`);
