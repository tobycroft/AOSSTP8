<?php

namespace app\v2\doudian\controller;

use app\v2\doudian\model\DoudianCookieModel;
use Input;
use Ret;

class cookie extends index
{
    public function get()
    {
        $appid = $this->project['appid'];
        $cookie = DoudianCookieModel::where('appid', $appid)->findOrEmpty();
        if ($cookie->isEmpty()) {
            Ret::Fail(404, null, 'Cookie not found');
        } else {
            Ret::Success(0, $cookie['cookie'], 'Cookie retrieved successfully');
        }
    }

    public function auto()
    {
        $appid = $this->project['appid'];
        $cookie = DoudianCookieModel::where('appid', $appid)->find();
        if ($cookie) {
            $cookie->cookie = Input::Post('cookie');
            $cookie->save();
        } else {
            $cookie = new DoudianCookieModel();
            $cookie->appid = $appid;
            $cookie->cookie = Input::Post('cookie');
            $cookie->save();
        }
    }
}