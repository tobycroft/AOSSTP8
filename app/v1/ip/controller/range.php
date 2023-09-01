<?php

namespace app\v1\ip\controller;

use app\v1\captcha\model\CaptchaIpModel;
use app\v1\image\controller\create;
use app\v1\ip\model\IpModel;
use Input;

class range extends create
{

    public function check()
    {
        $ip = Input::Post("ip");
        $country = Input::Post("country");
        $province = Input::PostJson("province");

        $data = IpModel::where("start_ip", "<=", $ip)
            ->where("end_ip", ">=", $ip)
            ->where("country", $country)
            ->whereIn("province", $province)
            ->find();
        if ($data) {
            \Ret::Success(0, true, "在IP列表中");
        } else {
            \Ret::Success(404, false, '不在IP列表中');
        }
    }

    public function auth()
    {
        $ip = Input::Post('ip');
        $country = Input::Post('country');
        $province = Input::PostJson('province');

        $data = IpModel::where('start_ip', '<=', $ip)
            ->where('end_ip', '>=', $ip)
            ->where('country', $country)
            ->whereIn('province', $province)
            ->find();
        if ($data) {
            \Ret::Success(0, true, '在IP列表中');
        } else {
            $captcha = CaptchaIpModel::where('ident', $ip)->where("date", ">")->find();
            if ($captcha) {
                \Ret::Success(0, true, '已通过验证码');
            } else {
                \Ret::Fail(103, false, "不在IP列表中，请先完成验证码");
            }
        }
    }

}