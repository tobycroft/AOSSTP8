<?php

namespace app\v1\ip\controller;

use BaseController\CommonController;
use Input;

class range extends CommonController
{

    public function check()
    {
        $ip = Input::Post("ip");
        $country = Input::Post("country");
        $province = Input::Post("province");
    }


}