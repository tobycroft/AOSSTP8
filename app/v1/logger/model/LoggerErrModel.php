<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace app\v1\logger\model;


use think\Model;

class LoggerErrModel extends Model
{

    public $table = 'ao_logger_err';

    public static function Api_insert($project, $log, $discript)
    {
        self::create([
            'project' => $project,
            'log' => $log,
            'discript' => $discript,
        ]);
    }

}
