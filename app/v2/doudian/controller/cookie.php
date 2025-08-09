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
        $cookie = DoudianCookieModel::where('appid', '=', $appid)->findOrEmpty();
        if ($cookie->isEmpty()) {
            Ret::Fail(404, null, 'Cookie not found' . $appid);
        } else {
            Ret::Success(0, json_decode($cookie['cookie']), 'Cookie retrieved successfully');
        }
    }

    public function auto()
    {
        $appid = $this->project['appid'];
        $cookie = (new DoudianCookieModel)->where('appid', $appid)->find();
        $data=Input::PostJson('data');
        if (!$cookie) {
            if (DoudianCookieModel::insert([
                'appid' => $appid,
                'cookie' => json_encode($data,320),
            ])) {
                Ret::Success(0, null, 'Cookie saved successfully');
            } else {
                Ret::Fail(500, null, 'Failed to save cookie');
            }
        } else {
            if (DoudianCookieModel::where('appid', $appid)->force()->update([
                'cookie' => json_encode($data,320),
            ])) {
                Ret::Success(0, null, 'Cookie updated successfully');
            } else {
                Ret::Fail(500, null, 'Failed to update cookie');
            }
        }
    }
}