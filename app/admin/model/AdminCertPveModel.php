<?php

namespace app\admin\model;

use think\Model;

class AdminCertPveModel extends Model
{
    protected $table = 'ao_cert_pve';

    /**
     * 表使用 date/change_date 由数据库自动维护，关闭框架时间戳写入
     */
    protected $autoWriteTimestamp = false;
}
