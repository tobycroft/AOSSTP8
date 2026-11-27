<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace app\v1\file\model;

use think\Model;

/**
 * ao_attachment 索引留档（2026-11-27 已在生产环境 mysql.aerofsx.com/aoss 执行）
 *
 * 保留：PRIMARY(id)、uk_v3_active_md5(函数唯一索引)、idx_create_time、idx_isdel_tag_ext。
 * 新增：
 *   idx_token_md5_tag_del (token, md5, tag, is_deleted)  -- V1 dp 秒传 / V3 md5 IN + token 查询
 *   idx_md5_tag_del       (md5, tag, is_deleted)        -- V3 全局秒传，ORDER BY id 可直接走主键后缀
 *   idx_token_tag_id_del  (token, tag, id, is_deleted)  -- 图床按用户分页，保留 id 排序能力
 *   idx_isdel_tag_deleted_at (is_deleted, tag, deleted_at) -- 回收站按软删时间倒序
 * 移除：token,md5（被 idx_token_md5_tag_del 覆盖）、md5（被 idx_md5_tag_del 覆盖）、
 *       idx_token_tag（被 idx_token_tag_id_del 覆盖）、idx_ip（全项目无附件 ip 查询）。
 *
 * ALTER TABLE `ao_attachment`
 *   ALGORITHM=INPLACE, LOCK=NONE,
 *   DROP INDEX `token,md5`, DROP INDEX `md5`, DROP INDEX `idx_token_tag`, DROP INDEX `idx_ip`,
 *   ADD INDEX `idx_token_md5_tag_del` (`token`,`md5`,`tag`,`is_deleted`),
 *   ADD INDEX `idx_md5_tag_del` (`md5`,`tag`,`is_deleted`),
 *   ADD INDEX `idx_token_tag_id_del` (`token`,`tag`,`id`,`is_deleted`),
 *   ADD INDEX `idx_isdel_tag_deleted_at` (`is_deleted`,`tag`,`deleted_at`);
 */
class AttachmentModel extends Model
{

    protected $table = 'ao_attachment';


}
