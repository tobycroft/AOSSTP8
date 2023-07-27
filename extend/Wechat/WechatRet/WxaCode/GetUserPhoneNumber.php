<?php

namespace Wechat\WechatRet\WxaCode;

class GetUserPhoneNumber
{
    public $response;
    public mixed $phoneNumber;
    public mixed $purePhoneNumber;
    public mixed $countryCode;
    public mixed $watermark;
    protected $data;
    protected mixed $error;
    protected int $errcode = 0;


    public function __construct($json)
    {
        $this->response = $json;
        $data = json_decode($json, 1);
        if (isset($data['errcode']) && $data['errcode'] !== 0) {
            $this->error = $data['errmsg'];
            $this->errcode = $data['errcode'];
        } else {
            $this->data = $data;
            $this->phoneNumber = $this->data['phone_info']['phoneNumber'] ?? "";
            $this->purePhoneNumber = $this->data['phone_info']['purePhoneNumber'] ?? "";
            $this->countryCode = $this->data['phone_info']['countryCode'] ?? "";
            $this->watermark = $this->data['phone_info']['watermark'] ?? "";
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