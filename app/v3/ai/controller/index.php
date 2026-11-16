<?php

namespace app\v3\ai\controller;

use app\v3\ai\model\AiModel;
use BaseController\CommonController;
use Input;
use Ret;

class index extends CommonController
{
    public mixed $token;
    public mixed $proc;

    public function initialize()
    {
        set_time_limit(0);
        parent::initialize();
        $this->token = Input::Combi('token', false);
        if (empty($this->token)) {
            Ret::Fail(400, null, '缺少参数token');
        }
        $this->proc = AiModel::api_find_token($this->token);
        if (empty($this->proc)) {
            Ret::Fail(401, null, '项目不可用');
        }
    }
}