<?php

namespace app\v1\captcha\controller;

use app\v1\captcha\model\CaptchaModel;
use app\v1\captcha\utils\SlideCaptcha;
use app\v2\project\model\ProjectModel;
use BaseController\CommonController;
use Input;
use Ret;

class slide extends CommonController
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

    public function create()
    {
        $config = [
            'bgWidth' => 300,
            'bgHeight' => 150,
            'blockSize' => 40,
            'radius' => 9,
            'tolerance' => 4,
        ];
        $capt = new SlideCaptcha($config);
        $data = $capt->generate();

        CaptchaModel::create([
            'ident' => $this->ident,
            'code' => (string) $capt->x,
            'hash' => $capt->hash,
            'type' => 'gif',
        ]);

        Ret::Success(0, $data);
    }

    public function check()
    {
        $x = Input::PostInt('x');
        $capt = CaptchaModel::where('ident', $this->ident)->where('type', 'gif')->find();
        if (!$capt) {
            Ret::Fail(403, null, '验证码不存在');
        }
        $answer = intval($capt['code']);
        $tolerance = 4;
        if (abs($x - $answer) <= $tolerance) {
            CaptchaModel::where('ident', $this->ident)->delete();
            Ret::Success(0, null, '验证成功');
        } else {
            Ret::Fail(403, null, '验证失败');
        }
    }
}