<?php

namespace app\admin\model;

use think\Model;

/**
 * 后台 V3 存储管理：ao_v3_storage（Bucket 自身配置）
 *
 * Bucket 自带 Path / 允许后缀 / 大小上限 / 存本地或远程，
 * 项目与 Bucket 的一对多关系在 ao_v3_storage_project 中维护。
 * project_id 仅保留为历史兼容列，不参与业务逻辑。
 * 不含 ao_oss 的 token / code / main_type 等历史概念。
 */
class AdminV3StorageModel extends Model
{
    protected $table = 'ao_v3_storage';
}
