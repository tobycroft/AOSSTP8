<?php

namespace app\v1\wechat\controller;

use Input;
use Ret;
use think\Request;
use Wechat\Miniprogram;
use Wechat\OfficialAccount;

class sns extends wxa
{


    public function jscode(Request $request)
    {
        if (!$request->has("js_code")) {
            Ret::Fail(400, null, "js_code");
        }
        $js_code = input('js_code');

        $wxa = Miniprogram::jscode2session($this->appid, $this->appsecret, $js_code, "authorization_code");
        if ($wxa->isSuccess()) {
            Ret::Success(0, [
                "openid" => $wxa->openid,
                "unionid" => $wxa->unionid,
                "session_key" => $wxa->session_key,
            ]);
        } else {
            Ret::Fail(300, $wxa->response, $wxa->getError());
        }
    }

    public function jscode2session(Request $request)
    {
        $this->jscode($request);
    }

    public function auth()
    {
        $access_token = Input::Post('access_token');
        $openid = Input::Post('openid');
        $auth = OfficialAccount::snsAuth($access_token, $openid);
        if ($auth->isSuccess()) {
            Ret::Success();
        } else {
            Ret::Fail($auth->getErrcode(), $auth->response, $auth->getError());
        }
    }

}
