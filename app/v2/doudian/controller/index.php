<?php

namespace app\v2\doudian\controller;

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
        if (empty($this->token)) {
            $appid = Input::Get('appid');
            if (empty($appid)) {
                Ret::Fail(400, null, '缺少参数appid');
            }
            $this->project = (new ProjectModel)->api_find_appid($appid);
            if (!$this->project) {
                Ret::Fail(401, null, '项目不可用');
            }
            $salt = Input::Post('salt');
            if (md5($this->project['appid'] . $this->project['appsecret'] . $salt) != Input::Get('sign')) {
                Ret::Fail(401, null, '签名错误');
            }
        } else {
            $this->project = (new ProjectModel)->api_find_token($this->token);
            if (!$this->project) {
                Ret::Fail(401, null, '项目不可用');
            }
        }
    }
}