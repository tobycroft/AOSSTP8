<?php

namespace app\v1\cert\model;

use think\Model;

class CertIkuaiModel extends Model
{
    protected $table = 'ao_cert_ikuai';

    /**
     * 表使用 date/change_date 由数据库自动维护，关闭框架时间戳写入
     */
    protected $autoWriteTimestamp = false;
}
