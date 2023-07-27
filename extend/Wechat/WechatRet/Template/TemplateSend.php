<?php

namespace Wechat\WechatRet\Template;


class TemplateSend
{

    public $response;
    protected $data;
    protected int $errcode = 0;
    protected $msgid;
    private $error;

    public function __construct($json)
    {
        $this->response = $json;
        $data = json_decode($json, 1);
        if (isset($data['errcode']) && $data['errcode'] !== 0) {
            $this->error = $data['errmsg'];
            $this->errcode = $data['errcode'];
        } else {
            $this->data = $json;
            $this->msgid = $data["msgid"];
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

    public function getData()
    {
        return $this->msgid;
    }
}