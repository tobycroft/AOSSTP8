<?php

namespace app\v1\captcha\controller;

use app\v1\captcha\model\CaptchaModel;
use app\v2\project\model\ProjectModel;
use BaseController\CommonController;
use Input;
use Ret;

class gif extends CommonController
{

    protected string $ident;
    public $token;
    public mixed $proc;

    public function initialize()
    {
        parent::initialize();
        set_time_limit(0);
        if (!$this->token) {
            $this->token = Input::Combi('token');
        }
        $this->proc = (new ProjectModel())->api_find_token($this->token);
        if (!$this->proc) {
            Ret::Fail(401, null, '项目不可用');
        }
        $this->ident = Input::Post('ident');
    }

    public function text()
    {
        $config = [
            'length' => 4,
            'codeSet' => '0123456789QWERTYUIOPASDFGHJKLZXCVBNM',
            'expire' => 1800,
            'fontSize' => 25,
            'bg' => [243, 251, 254],
            'useCurve' => true,
            'useNoise' => true,
            'frameDelay' => 100,
            'imageW' => 0,
            'imageH' => 0,
        ];
        return $this->generateGif($config);
    }

    public function fast()
    {
        $config = [
            'length' => 4,
            'codeSet' => '0123456789QWERTYUIOPASDFGHJKLZXCVBNM',
            'expire' => 1800,
            'fontSize' => 25,
            'bg' => [243, 251, 254],
            'useCurve' => true,
            'useNoise' => true,
            'frameDelay' => 50,
            'imageW' => 0,
            'imageH' => 0,
        ];
        return $this->generateGif($config);
    }

    public function number()
    {
        $config = [
            'length' => 4,
            'codeSet' => '0123456789',
            'expire' => 1800,
            'fontSize' => 25,
            'bg' => [243, 251, 254],
            'useCurve' => true,
            'useNoise' => true,
            'frameDelay' => 100,
            'imageW' => 0,
            'imageH' => 0,
        ];
        return $this->generateGif($config);
    }

    public function number_fast()
    {
        $config = [
            'length' => 4,
            'codeSet' => '0123456789',
            'expire' => 1800,
            'fontSize' => 25,
            'bg' => [243, 251, 254],
            'useCurve' => true,
            'useNoise' => true,
            'frameDelay' => 50,
            'imageW' => 0,
            'imageH' => 0,
        ];
        return $this->generateGif($config);
    }

    private function generateGif(array $config)
    {
        $capt = new \app\v1\captcha\utils\GifCaptcha($config);
        $response = $capt->create();
        CaptchaModel::create([
            'ident' => $this->ident,
            'code' => $capt->question,
            'hash' => $capt->hash,
            'type' => 'math',
        ]);
        return $response;
    }
}