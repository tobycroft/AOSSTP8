<?php

namespace app\v1\tasker\controller;

use app\v1\log\model\LogWebModel;
use Input;

class log
{
    public function upload()
    {
        $in = Input::Raw();
        LogWebModel::create([
            'get' => json_encode(request()->get(), 320),
            'post' => json_encode(request()->post(), 320),
            'raw' => $in,
            'header' => json_encode(request()->header(), 320),
            'method' => request()->method(),
        ]);
        echo 123;
    }

    public function time()
    {
        $time = Input::PostInt("time");

    }
}