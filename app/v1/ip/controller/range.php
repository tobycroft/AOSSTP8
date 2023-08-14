<?php

namespace app\v1\ip\controller;

use app\v1\ip\model\IpModel;
use BaseController\CommonController;
use Input;

class range extends CommonController
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
            \Ret::Success(0, false, '不在IP列表中');
        }
    }


}