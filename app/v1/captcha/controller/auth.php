<?php

namespace app\v1\captcha\controller;

use app\v1\captcha\model\CaptchaIpModel;
use app\v1\captcha\model\CaptchaModel;

class auth extends text
{
    /** 验证码有效期（秒），与生成端 expire 保持一致。 */
    private const TTL = 1800;

    public function check()
    {
        $this->verify(\Input::Post("code"), self::TTL);
    }

    public function check_in_time()
    {
        // second 由客户端提交，只允许缩短有效期，不允许超过服务端上限
        $second = \Input::PostInt("second");
        $ttl = ($second > 0 && $second < self::TTL) ? $second : self::TTL;
        $this->verify(\Input::Post("code"), $ttl);
    }

    /**
     * 校验失败即删除该 ident 的验证码，强制重新获取，避免穷举。
     */
    private function verify($code, int $ttl): void
    {
        $capt = CaptchaModel::where("code", $code)->where("ident", $this->ident)->find();
        if (!$capt) {
            CaptchaModel::where("ident", $this->ident)->delete();
            \Ret::Fail(403, null, "验证码错误");
        }
        if (strtotime((string)$capt["date"]) + $ttl < time()) {
            CaptchaModel::where("ident", $this->ident)->delete();
            \Ret::Fail(403, null, "验证码已经过期啦");
        }

        CaptchaIpModel::create(["ident" => $this->ident]);
        CaptchaModel::where("ident", $this->ident)->delete();
        \Ret::Success(0, null, "验证码正确");
    }

}