<?php

namespace app\v1\sms\controller;

use app\v1\oss\model\OssModel;
use app\v1\sms\action\SendAction;
use BaseController\CommonController;
use Input;
use Ret;

class single extends CommonController
{

    public mixed $token;
    public mixed $proc;

    public function initialize()
    {
        $this->token = Input::Post('name');
        $this->proc = OssModel::api_find_token($this->token);
        if (!$this->proc) {
            Ret::Fail(401, null, '项目不可用');
        }
        $ts = Input::PostInt('ts');
        $sign = Input::Post('sign');
        if (md5($this->proc['code'] . $ts) != $sign) {
            Ret::Fail(401, null, '签名不正确，加密方式为小写MD5(密钥code+ts)');
        }
    }

    public function push()
    {
        $phone = Input::Post("phone");
        $quhao = Input::PostInt("quhao");
        $text = Input::Post("text");
        $ip = Input::Post("ip");
        if (strlen($phone) < 6) {
            Ret::Fail(400, null, '手机号长度不正确');
        }
        if ($std = SendAction::AutoSend($this->proc, $quhao, $phone, $text, $ip)) {
            Ret::Success($std->getCode(), $std->getData(), $std->getError());
        } else {
            Ret::Fail(406, null, "短信平台未选择");
        }
    }

    public function push_verify()
    {
        $phone = Input::Post('phone');
        $quhao = Input::PostInt('quhao');
        $text = Input::Post('text');
        $ip = Input::Post('ip');
        if (strlen($phone) < 6) {
            Ret::Fail(400, null, '手机号长度不正确');
        }
        if ($std = SendAction::AutoSend($this->proc, $quhao, $phone, $text, $ip)) {
            Ret::Success($std->getCode(), $std->getData(), $std->getError());
        } else {
            Ret::Fail(406, null, '短信平台未选择');
        }
    }
}