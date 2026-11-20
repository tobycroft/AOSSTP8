<?php

namespace app\admin\model;

use think\Model;

/**
 * 后台 V3 存储管理：ao_v3_storage（一项目一存储，独立于 ao_oss）
 */
class AdminV3StorageModel extends Model
{
    protected $table = 'ao_v3_storage';
}
