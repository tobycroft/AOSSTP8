<?php

namespace app\v1\captcha\controller;

use app\v1\image\controller\create;
use think\captcha\Captcha;

class text extends create
{
    public function create()
    {
        $capt = \think\captcha\facade\Captcha::create();
        $capt->code("123");
        return $capt;
    }
}