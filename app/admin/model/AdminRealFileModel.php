<?php

namespace app\admin\model;

use think\Model;

/**
 * upload 目录实际文件索引
 *
 * 完全独立于 ao_attachment：只反映 public/upload 磁盘现状，
 * 由后台「实际文件管理」手动扫描回填，不参与 V1/V2/V3 上传链路。
 *
 * 建表语句（项目无迁移脚本，DDL 在此留档，需在各环境手动执行）：
 *
 * CREATE TABLE `ao_real_file` (
 *   `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
 *   `path_hash` char(32) NOT NULL DEFAULT '' COMMENT 'md5(path)，唯一键，避免长 varchar 索引',
 *   `path` varchar(1000) NOT NULL DEFAULT '' COMMENT '相对 upload 根目录的 POSIX 路径',
 *   `parent` varchar(1000) NOT NULL DEFAULT '' COMMENT '父目录相对路径，根目录项为空',
 *   `name` varchar(255) NOT NULL DEFAULT '' COMMENT '文件或目录名',
 *   `type` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '1=文件 2=目录',
 *   `size` bigint unsigned NOT NULL DEFAULT 0 COMMENT '文件字节数，目录为 0',
 *   `ext` varchar(32) NOT NULL DEFAULT '' COMMENT '小写扩展名，目录为空',
 *   `mime` varchar(128) NOT NULL DEFAULT '' COMMENT 'MIME 类型（按扩展名推断）',
 *   `mtime` int unsigned NOT NULL DEFAULT 0 COMMENT '磁盘最后修改时间（Unix 秒）',
 *   `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '1=磁盘存在 0=同步时未再发现',
 *   `sync_time` int unsigned NOT NULL DEFAULT 0 COMMENT '最近一次同步时间（Unix 秒）',
 *   `create_time` int unsigned NOT NULL DEFAULT 0 COMMENT '首次入库时间（Unix 秒）',
 *   PRIMARY KEY (`id`),
 *   UNIQUE KEY `uk_path_hash` (`path_hash`),
 *   KEY `idx_type_status` (`type`, `status`)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='upload 实际文件索引（独立于 ao_attachment）';
 */
class AdminRealFileModel extends Model
{
    protected $table = 'ao_real_file';
}
