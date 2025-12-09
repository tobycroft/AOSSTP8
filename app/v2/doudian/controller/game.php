<?php

namespace app\v2\doudian\controller;

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
        $game_data = (new DoudianGameWheelModel())->order("num asc")->select();
        \Ret::Success(0, $game_data);
    }
}