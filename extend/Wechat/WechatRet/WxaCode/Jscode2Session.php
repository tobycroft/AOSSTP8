<?php

namespace Wechat\WechatRet\WxaCode;


class Jscode2Session
{
    public $response;
    public mixed $session_key;
    public mixed $unionid;
    public mixed $openid;
    protected $data;
    protected mixed $error;
    protected int $errcode = 0;


    public function __construct($json)
    {
        $this->response = $json;
        $data = json_decode($json, 1);
        if (isset($data['errmsg'])) {
            $this->error = $data['errmsg'];
            $this->errcode = $data['errcode'];
        } else {
            $this->data = $data;
            $this->openid = $this->data['openid'] ?? "";
            $this->session_key = $this->data['session_key'] ?? "";
            $this->unionid = $this->data['unionid'] ?? "";
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

    public function getError()
    {
        return $this->error;
    }

    public function getErrcode(): int
    {
        return $this->errcode;
    }
}