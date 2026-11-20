<?php

namespace app\admin\model;

use think\Model;

/**
 * 后台 V3 存储管理：ao_v3_storage（一项目一 Bucket）
 *
 * Bucket 自带 Path / 允许后缀 / 大小上限 / 存本地或远程，
 * 不含 ao_oss 的 token / code / main_type 等历史概念。
 */
class AdminV3StorageModel extends Model
{
    protected $table = 'ao_v3_storage';
}
