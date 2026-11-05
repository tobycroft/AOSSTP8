-- Proxmox VE 证书节点：增加节点备注字段
-- 执行方式：mysql -h <host> -u <user> -p <database> < sql/20261105_alter_ao_cert_pve_add_remark.sql
-- 说明：与仓库内证书URL的 remark 约定一致，使用 varchar(255)，允许为空，默认空字符串。

ALTER TABLE `ao_cert_pve`
  ADD COLUMN `remark` varchar(255) DEFAULT '' COMMENT '节点备注' AFTER `node`;
