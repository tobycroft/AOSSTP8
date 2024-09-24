<?php

namespace app\v1\cert\controller;

use BaseController\CommonController;

class bt extends CommonController
{

    public function initialize()
    {
        set_time_limit(0);
        parent::initialize();
    }

    public function test()
    {

    }
}