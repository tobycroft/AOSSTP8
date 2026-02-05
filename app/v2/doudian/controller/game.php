<?php

namespace app\v2\doudian\controller;

use app\v2\doudian\model\DoudianGameFallModel;
use app\v2\doudian\model\DoudianGameFankaModel;
use app\v2\doudian\model\DoudianGameModel;
use app\v2\doudian\model\DoudianGameWheelModel;
use BaseController\CommonController;

class game extends CommonController
{
    public function rand2()
    {
        $num = \Input::PostInt("num");
        $game_data = (new DoudianGameModel())->where("num", $num)->find();
        \Ret::Success(0, $game_data);
    }

    public function wheel()
    {
        $game_data = (new DoudianGameWheelModel())->where("status", "=", 1)->order("num asc")->select();
        \Ret::Success(0, $game_data);
    }

    public function fall()
    {
        $pack = \Input::PostInt("pack");
        $game_data = (new DoudianGameFallModel())->where("pack", "=", $pack)->where("status", "=", 1)->order("rank asc")->select();
        \Ret::Success(0, $game_data);
    }

    public function fanka()
    {
        $pack = \Input::PostInt('pack');
        $psh = \Input::PostInt('push');
        $game_data = (new DoudianGameFankaModel())->where('pack', '=', $pack)->where('psh', '=', $psh)->where('status', '=', 1)->select();
        \Ret::Success(0, $game_data);
    }
}