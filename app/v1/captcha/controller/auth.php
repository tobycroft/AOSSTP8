<?php

namespace app\v1\captcha\controller;

use app\v1\captcha\model\CaptchaIpModel;
use app\v1\captcha\model\PrintUserModel;

class auth extends text
{
    public function check()
    {
        $code = \Input::Post("code");
        $capt = PrintUserModel::where("code", $code)->where("ident", $this->ident)->find();
        if ($capt) {
            CaptchaIpModel::create(["ident" => $this->ident]);
            PrintUserModel::where("ident", $this->ident)->delete();
            \Ret::Success(0, null, "验证码正确");
        } else {
            \Ret::Fail(403, null, "验证码错误");
        }
    }

}