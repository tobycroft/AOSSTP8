<?php

namespace Wechat\WechatRet;


use Wechat\Miniprogram;

class GetAccessToken extends Miniprogram
{
    public $response;
    public $access_token;
    public $expires_in;
    protected int $errcode = 0;
    private $error;

    public function __construct($json)
    {
        $this->response = $json;
        $data = json_decode($json, 1);
        if (isset($data["errmsg"])) {
            $this->error = $data["errmsg"];
            $this->errcode = $data['errcode'];
        } else {
            $this->access_token = $data["access_token"];
            $this->expires_in = $data["expires_in"];
        }
    }

    public function isSuccess()
    {
        if (isset($this->error)) {
            return false;
        } else {
            return true;
        }
    }

    public function error()
    {
        return $this->error;
    }

    public function getErrcode(): int
    {
        return $this->errcode;
    }
}