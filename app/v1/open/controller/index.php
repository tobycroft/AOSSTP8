<?php

namespace app\v1\open\controller;

use BaseController\CommonController;

class index extends CommonController
{

    public function wechat()
    {
        $code = input('code');
        $state = input('state');
        $this->success(0, $code);
    }

}