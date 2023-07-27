<?php

namespace Wechat\WechatRet\WxaCode;

class GenerateScheme
{
    public $response;
    public mixed $openlink;
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
            $this->openlink = $this->data['openlink'] ?? "";
        }
    }

    public function isSuccess(): bool
    {
        if (isset($this->error)) {
            return false;
        } else {
            return true;
        }
    }

    public function getError(): mixed
    {
        return $this->error;
    }

    public function getErrcode(): int
    {
        return $this->errcode;
    }
}