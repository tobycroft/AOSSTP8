<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace app\v1\project\model;


use think\Model;

class ProjectModel extends Model
{

    protected $table = 'ao_project';

    public static function api_find_token($token): array
    {
        return self::where('token', $token)->where('status', 1)->find()->toArray();
    }


}
