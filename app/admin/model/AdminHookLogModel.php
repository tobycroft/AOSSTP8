<?php

namespace app\admin\model;

use think\Model;

class AdminHookLogModel extends Model
{
    protected $table = 'ao_hook_log';
    protected $autoWriteTimestamp = false;
}