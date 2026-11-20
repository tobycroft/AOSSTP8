<?php

namespace app\v3\storage\model;

use think\Model;

/*
CREATE TABLE `ao_v3_storage`:
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `project_id` int unsigned NOT NULL DEFAULT 0 COMMENT '所属 V3 项目 id（一项目一存储）',
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '存储名称，upload_folder 为空时回退用',
  `type` enum('local','oss','all','remote','none') NOT NULL DEFAULT 'none',
  `main_type` enum('oss','local') NOT NULL DEFAULT 'local',
  `code` varchar(100) NOT NULL DEFAULT '',
  `token` varchar(100) NOT NULL DEFAULT '' COMMENT '存储标识，写入一次性 token 供直传定位；实际使用须 <= 64 字符（会写入 ao_attachment_token.oss_token / ao_attachment.token）',
  `oss_type` enum('none','aliyun','tencent') DEFAULT 'none',
  `oss_tag` varchar(255) DEFAULT 'default',
  `url` varchar(255) DEFAULT '',
  `ext` varchar(100) NOT NULL DEFAULT 'jpg,png,gif,bmp,jpeg',
  `size` int DEFAULT 16384,
  `upload_folder` varchar(100) NOT NULL DEFAULT '' COMMENT '上传文件夹，相对 upload/；为空回退存储名称',
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `date` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_v3s_project` (`project_id`),
  KEY `idx_v3s_token` (`token`)

V3 存储是独立配置项，与 ao_oss（V1/V2 存储项目）无任何绑定关系：
  - 一项目一存储：project_id 唯一，存储项里指定所属 V3 项目；
  - 上传文件夹 upload_folder 也在存储项里，为空回退 name；
  - local 存本站 ./upload/，oss 传远程，存储类型逻辑完全复用 V1。
本表字段刻意与 ao_oss 对齐（除 project_id / upload_folder），
使 v3 直传能直接把本表行当作 V1 upload_file 所需的 $proc 使用。
 */
class V3StorageModel extends Model
{
    protected $table = 'ao_v3_storage';

    /**
     * 按所属项目查找启用中的存储配置（签发接口用）
     *
     * 用查询构造器直接取数组：避开 Model::toArray() 在 PHP 8.6 下对字符串属性
     * 调用 is_subclass_of(..., false) 触发的弃用告警。
     *
     * @param int $projectId ao_v3_project.id
     * @return array 存储配置行，或 []
     */
    public static function api_find_project(int $projectId): array
    {
        if ($projectId <= 0) {
            return [];
        }
        return \think\facade\Db::name('ao_v3_storage')
            ->where('project_id', $projectId)
            ->where('status', 1)
            ->findOrEmpty();
    }

    /**
     * 按存储标识查找启用中的存储配置（直传落盘定位用）
     */
    public static function api_find_token(string $token): array
    {
        if ($token === '') {
            return [];
        }
        return \think\facade\Db::name('ao_v3_storage')
            ->where('token', $token)
            ->where('status', 1)
            ->findOrEmpty();
    }
}
