<?php

namespace app\v1\captcha\controller;

use app\v1\captcha\model\CaptchaIpModel;
use app\v1\captcha\model\CaptchaModel;

class auth extends text
{
    public function check()
    {
        $code = \Input::Post("code");
        $capt = CaptchaModel::where("code", $code)->where("ident", $this->ident)->find();
        if ($capt) {
            CaptchaIpModel::create(["ident" => $this->ident]);
            CaptchaModel::where("ident", $this->ident)->delete();
            \Ret::Success(0, null, "验证码正确");
        } else {
            \Ret::Fail(403, null, "验证码错误");
        }
    }

    public function check_in_time()
    {
        $code = \Input::Post("code");
        $second = \Input::Post("second");
        $capt = CaptchaModel::where("code", $code)->where("ident", $this->ident)->find();
        if ($capt) {
            if (strtotime($capt["date"]) - $second < time()) {
                \Ret::Fail(403, null, "验证码已经过期啦");
            }
            CaptchaIpModel::create(["ident" => $this->ident]);
            CaptchaModel::where("ident", $this->ident)->delete();
            \Ret::Success(0, null, "验证码正确");
        } else {
            \Ret::Fail(403, null, "验证码错误");
        }
    }

}