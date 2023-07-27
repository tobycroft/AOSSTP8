<?php

namespace app\v1\rsa\controller;


use app\v1\rsa\action\Rsa;
use BaseController\CommonController;

class index extends CommonController
{

    public function index()
    {
        $rsa = new Rsa();
        $time1 = microtime();
        $sign = $rsa->sign("test");
        $time2 = microtime();
        \Ret::Success(0, $sign, $time2 - $time1);
    }

    public function index2()
    {
        $rsa = new Rsa();
        $time1 = microtime();
        $sign = $rsa->createSign("test");
        $time2 = microtime();
        \Ret::Success(0, $sign, $time2 - $time1);
    }
}