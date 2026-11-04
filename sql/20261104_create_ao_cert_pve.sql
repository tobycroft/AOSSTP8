-- Proxmox VE 自定义证书下发：节点鉴权配置表
-- 执行方式：mysql -h <host> -u <user> -p <database> < sql/20261104_create_ao_cert_pve.sql
-- 说明：下发日志继续写入 ao_cert_log，type = 'pve'，与爱快（type = 'ikuai'）保持同一日志页与筛选方式。

CREATE TABLE IF NOT EXISTS `ao_cert_pve` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `api` varchar(255) DEFAULT '' COMMENT 'PVE API 地址，如 https://10.0.0.10:8006',
  `node` varchar(128) DEFAULT '' COMMENT 'PVE 节点名称，如 pve',
  `token_id` varchar(255) DEFAULT '' COMMENT 'API Token 标识，格式 user@realm!tokenid',
  `token_secret` varchar(255) DEFAULT '' COMMENT 'API Token 密钥',
  `cert_name` varchar(255) DEFAULT '' COMMENT '绑定的证书名称，对应 ao_cert_url.cert',
  `verify_tls` tinyint DEFAULT '0' COMMENT '是否校验 PVE 证书：1是 0否（PVE 自签证书建议设置为 0）',
  `restart_proxy` tinyint DEFAULT '1' COMMENT '下发后是否重启 pveproxy 使证书立即生效：1是 0否',
  `last_deploy_time` datetime DEFAULT NULL COMMENT '最近一次证书下发时间',
  `last_deploy_status` tinyint DEFAULT '-1' COMMENT '最近一次下发结果：-1未下发 0失败 1成功',
  `last_error` varchar(255) DEFAULT '' COMMENT '最近一次下发或查询的错误信息',
  `status` tinyint DEFAULT '1' COMMENT '状态：1启用 0禁用',
  `change_date` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `date` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `api_node` (`api`,`node`),
  KEY `idx_cert_name` (`cert_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Proxmox VE 证书下发节点';
