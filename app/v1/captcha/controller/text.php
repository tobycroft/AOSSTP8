<?php

namespace app\v1\captcha\controller;

use app\v1\captcha\model\CaptchaModel;
use app\v1\captcha\utils\Captcha;
use app\v2\project\model\ProjectModel;
use BaseController\CommonController;
use Input;
use Ret;
use think\Config;
use think\Response;
use think\Session;

class text extends CommonController
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


    public function chinese()
    {
        $config = [
            'length' => 2,
            'codeSet' => '0123456789',
            'expire' => 1800,
            'useZh' => true,
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
        $capt = new Captcha($con, $sess);
        $create = $capt->create();
        CaptchaModel::create([
            'ident' => $this->ident,
            'code' => $capt->question,
            'hash' => $capt->hash,
            'type' => 'math',
        ]);
        return $create;
    }

    public function number()
    {
        $config = [
            'length' => 4,
            'codeSet' => '0123456789',
            'expire' => 1800,
            'useZh' => false,
            'math' => false,
            'useImgBg' => false,
            'fontSize' => 25,
            'useCurve' => false,
            'useNoise' => true,
            'fontttf' => '',
            'bg' => [243, 251, 254],
            'imageH' => 0,
            'imageW' => 0,
        ];
        return $this->Generated($config);
    }

    public function text()
    {
        $config = [
            'length' => 4,
            'codeSet' => '0123456789QWERTYUIOPASDFGHJKLZXCVBNM',
            'expire' => 1800,
            'useZh' => false,
            'math' => false,
            'useImgBg' => false,
            'fontSize' => 25,
            'useCurve' => false,
            'useNoise' => true,
            'fontttf' => '',
            'bg' => [243, 251, 254],
            'imageH' => 0,
            'imageW' => 0,
        ];
        return $this->Generated($config);
    }

    public function gif()
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

    public function gif_fast()
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

    public function math()
    {
        $config = [
            'length' => 4,
            'codeSet' => '0123456789',
            'expire' => 1800,
            'useZh' => false,
            'math' => true,
            'useImgBg' => false,
            'fontSize' => 25,
            'useCurve' => false,
            'useNoise' => true,
            'fontttf' => '',
            'bg' => [243, 251, 254],
            'imageH' => 0,
            'imageW' => 0,
        ];
        $con = new Config();
        $con->set($config, "captcha");

        $sess = new Session($this->app);
        $capt = new Captcha($con, $sess);
        $create = $capt->create();
        CaptchaModel::create([
            'ident' => $this->ident,
            "code" => $capt->key,
            "hash" => $capt->hash,
            "type" => "math",
        ]);
        return $create;
    }

    /**
     * @param array $config
     * @return Response
     */
    private function Generated(array $config): Response
    {
        $con = new Config();
        $con->set($config, "captcha");

        $sess = new Session($this->app);
        $capt = new Captcha($con, $sess);
        $create = $capt->create();
        CaptchaModel::create([
            "ident" => $this->ident,
            "code" => $capt->question,
            "hash" => $capt->hash,
            "type" => "math",
        ]);
        return $create;
    }
}