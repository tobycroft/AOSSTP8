<?php

namespace app\v2\doudian\controller;

use app\v2\doudian\model\DoudianCookieModel;
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
}