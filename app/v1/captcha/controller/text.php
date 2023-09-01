<?php

namespace app\v1\captcha\controller;

use app\v1\captcha\model\CaptchaModel;
use app\v1\captcha\utils\Captcha;
use app\v1\image\controller\create;
use think\Session;

class text extends create
{
    public function create()
    {

        $ident = \Input::Post("ident");
        $config = [
            //验证码位数
            'length' => 4,
            // 验证码字符集合
            'codeSet' => '0123456789',
            // 验证码过期时间
            'expire' => 1800,
            // 是否使用中文验证码
            'useZh' => false,
            // 是否使用算术验证码
            'math' => true,
            // 是否使用背景图
            'useImgBg' => false,
            //验证码字符大小
            'fontSize' => 25,
            // 是否使用混淆曲线
            'useCurve' => false,
            //是否添加杂点
            'useNoise' => true,
            // 验证码字体 不设置则随机
            'fontttf' => '',
            //背景颜色
            'bg' => [243, 251, 254],
            // 验证码图片高度
            'imageH' => 0,
            // 验证码图片宽度
            'imageW' => 0,

            // 添加额外的验证码设置
            // verify => [
            //     'length'=>4,
            //    ...
            //],
        ];
        $con = new \think\Config();
        $con->set($config, "captcha");

        $sess = new Session($this->app);
        $capt = new Captcha($con, $sess);
        $create = $capt->create();
        CaptchaModel::create([
            "ident" => $ident,
            "code" => $capt->question,
            "hash" => $capt->hash,
            "type" => "math",
        ]);
        return $create;
    }
}