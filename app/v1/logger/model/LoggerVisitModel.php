<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace app\v1\logger\model;


use think\cache\driver\Redis;
use think\Model;

class LoggerVisitModel extends Model
{

    public $table = 'ao_logger_visit';

    public function Api_insert($project, $ip, $host, $path, $header, $request, $change_date)
    {
        $red = new \Redis();
        $red->lPush("__AOSSTP__" . __CLASS__ . __FUNCTION__, json_encode([
                'project' => $project,
                'ip' => $ip,
                'host' => $host,
                'path' => $path,
                'header' => $header,
                'request' => $request,
                'change_date' => $change_date,
            ], 320)
        );
    }

    public function Api_insert_all()
    {
        $redis = new Redis();
        $red = $redis->handler();
        $red->multi();
        $data = $red->lRange('__AOSSTP__' . __CLASS__ . __FUNCTION__, 0, -1);
        if (self::insertAll($data)) {
            $red->exec();
        }
    }

}
