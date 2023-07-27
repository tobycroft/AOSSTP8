<?php

namespace Wechat\WechatRet;


class UserGet
{
    public $response;
    public mixed $total;
    public mixed $count;
    public array $openid;
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
            $this->data = $data['data'];
            $this->total = $data['total'] ?? "";
            $this->count = $data['count'] ?? "";
            $this->openid = $this->data['openid'] ?? [];
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