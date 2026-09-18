<?php

namespace app\index\controller;

use app\v1\captcha\utils\GifCaptcha;
use BaseController\CommonController;

class Captcha extends CommonController
{
    public function gif()
    {
        $config = [
            'length' => 4,
            'codeSet' => '0123456789',
            'fontSize' => 25,
            'useCurve' => true,
            'useNoise' => true,
            'bg' => [243, 251, 254],
            'frameDelay' => 100,
        ];

        $capt = new GifCaptcha($config);
        $response = $capt->create();

        // 验证码 hash 存入 cookie，供登录/注册校验
        setcookie('user_captcha', $capt->hash, [
            'expires' => time() + 300,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        return $response;
    }
}
