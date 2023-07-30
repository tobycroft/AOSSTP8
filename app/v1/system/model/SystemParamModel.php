<?php

namespace app\v1\system\model;

use think\Model;

class SystemParamModel extends Model
{
    protected $table = 'system_param';

    public static function api_find_key($key): string
    {
        return self::where('key', $key)->value("value");
    }
}