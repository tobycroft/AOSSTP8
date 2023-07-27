<?php

namespace BaseController;


use app\BaseController;

class CommonController extends BaseController
{
    public function initialize()
    {
        header("Access-Control-Allow-Origin: *", true);
        header("Access-Control-Max-Age: 86400", true);
        header("Access-Control-Allow-Credentials: true", true);
        header("Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS", true);
        header("Access-Control-Allow-Headers: *", true);
        // 服务启动
    }
}