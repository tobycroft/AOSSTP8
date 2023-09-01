<?php

namespace app\v1\captcha\controller;

use app\v1\captcha\model\CaptchaModel;

class auth extends text
{
    public function check()
    {
        $code = \Input::Post("code");
        $capt = CaptchaModel::where("code", $code)->where("ident", $this->ident)->find();
        if ($capt) {
            CaptchaModel::where("ident", $this->ident)->delete();
            \Ret::Success(0, null, "验证码正确");
        } else {
            \Ret::Fail(403, null, "验证码错误");
        }
    }

}