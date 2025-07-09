<?php

namespace app\v2\image\controller;

use app\v1\file\action\OssSelectionAction;
use app\v2\project\model\ProjectModel;
use BaseController\CommonController;
use Input;
use Ret;

class index extends CommonController
{

    public mixed $token;
    public mixed $project;

    public function initialize()
    {
        set_time_limit(0);
        parent::initialize();
        $this->token = Input::Get('token');
        $this->project = (new ProjectModel)->api_find_token($this->token);
        if (!$this->project) {
            Ret::Fail(401, null, '项目不可用');
        }
    }
}