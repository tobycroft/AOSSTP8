<?php

namespace Wechat\WechatRet;


use Wechat\Miniprogram;

class GetTicket extends Miniprogram
{
    public $response;
    public $ticket;
    public $expires_in;
    protected int $errcode = 0;
    private $error;

    public function __construct($json)
    {
        $this->response = $json;
        $data = json_decode($json, 1);
        if (isset($data['errcode']) && $data['errcode'] !== 0) {
            $this->error = $data["errmsg"];
            $this->errcode = $data['errcode'];
        } else {
            $this->ticket = $data["ticket"];
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