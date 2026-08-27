<?php

namespace app\admin\controller;

use app\admin\utils\Captcha as AdminCaptcha;
use BaseController\CommonController;

class Captcha extends CommonController
{
    public function gif()
    {
        $config = [
            'length' => 4,
            'codeSet' => '2345678abcdefhijkmnpqrstuvwxyz',
            'fontSize' => 25,
            'useCurve' => true,
            'useNoise' => true,
            'bg' => [243, 251, 254],
        ];

        $capt = new AdminCaptcha($this->app, $config);
        return $capt->create();
    }
}