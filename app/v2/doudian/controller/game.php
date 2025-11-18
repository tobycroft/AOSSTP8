<?php

namespace app\v2\doudian\controller;

use app\v2\doudian\model\DoudianGameModel;

class game extends index
{

    public function rand2()
    {
        $num = \Input::PostInt("num");
        $game_data = (new DoudianGameModel())->where("num", $num)->find();
        \Ret::Success(0, $game_data);
    }
}