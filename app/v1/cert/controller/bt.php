<?php

namespace app\v1\cert\controller;

use app\v1\project\model\ProjectModel;
use BaseController\CommonController;
use Input;
use Ret;

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