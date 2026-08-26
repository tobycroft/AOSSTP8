<?php

namespace app\admin\controller;

use app\admin\utils\Captcha as AdminCaptcha;
use BaseController\CommonController;
use think\Config;
use think\Session;

class Captcha extends CommonController
{
    public function gif()
    {
        $config = [
            'length' => 4,
            'codeSet' => '2345678abcdefhijkmnpqrstuvwxyz',
            'expire' => 1800,
            'useZh' => false,
            'math' => false,
            'useImgBg' => false,
            'fontSize' => 25,
            'useCurve' => true,
            'useNoise' => true,
            'fontttf' => '',
            'bg' => [243, 251, 254],
            'imageH' => 0,
            'imageW' => 0,
        ];

        $con = new Config();
        $con->set($config, 'captcha');

        $sess = new Session($this->app);
        $capt = new AdminCaptcha($con, $sess);
        return $capt->create();
    }
}