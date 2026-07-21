<?php

namespace app\v1\captcha\controller;

use app\v1\captcha\model\CaptchaModel;
use app\v1\captcha\utils\ClickCaptcha;
use app\v2\project\model\ProjectModel;
use BaseController\CommonController;
use Input;
use Ret;

class click extends CommonController
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
            'bgHeight' => 200,
            'fontSize' => 28,
            'tolerance' => 30,
        ];
        $capt = new ClickCaptcha($config);
        $data = $capt->generate();

        // 将目标位置 JSON 编码后存入 code 字段
        CaptchaModel::create([
            'ident' => $this->ident,
            'code' => json_encode($capt->targets, JSON_UNESCAPED_UNICODE),
            'hash' => $capt->hash,
            'type' => 'click',
        ]);

        Ret::Success(0, $data);
    }

    public function check()
    {
        $clicksRaw = Input::Post('clicks');
        $clicks = json_decode($clicksRaw, true);
        if (!is_array($clicks) || empty($clicks)) {
            Ret::Fail(403, null, '参数错误');
        }

        $capt = CaptchaModel::where('ident', $this->ident)->where('type', 'click')->find();
        if (!$capt) {
            Ret::Fail(403, null, '验证码不存在');
        }

        $targets = json_decode($capt['code'], true);
        if (!is_array($targets)) {
            Ret::Fail(403, null, '验证码数据异常');
        }

        $tolerance = 22;
        if (count($clicks) !== count($targets)) {
            CaptchaModel::where('ident', $this->ident)->delete();
            Ret::Fail(403, null, '点击数量不正确，请重新获取验证码');
        }

        foreach ($targets as $i => $target) {
            $click = $clicks[$i] ?? null;
            if ($click === null) {
                CaptchaModel::where('ident', $this->ident)->delete();
                Ret::Fail(403, null, '验证失败，请重新获取验证码');
            }
            $dx = (int)$click['x'] - (int)$target['x'];
            $dy = (int)$click['y'] - (int)$target['y'];
            $dist = sqrt($dx * $dx + $dy * $dy);
            if ($dist > $tolerance) {
                CaptchaModel::where('ident', $this->ident)->delete();
                Ret::Fail(403, null, '验证失败，请重新获取验证码');
            }
        }

        CaptchaModel::where('ident', $this->ident)->delete();
        Ret::Success(0, null, '验证成功');
    }
}