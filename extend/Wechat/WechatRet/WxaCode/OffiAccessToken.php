<?php

namespace Wechat\WechatRet\WxaCode;


class OffiAccessToken
{
    public string $response;
    public mixed $access_token;
    public mixed $refresh_token;
    public mixed $expires_in;
    public mixed $openid;
    public mixed $scope;
    public mixed $is_snapshotuser;
    protected mixed $data;
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
            $this->access_token = $this->data['access_token'] ?? "";
            $this->refresh_token = $this->data['refresh_token'] ?? "";
            $this->expires_in = $this->data['expires_in'] ?? "";
            $this->openid = $this->data['openid'] ?? "";
            $this->scope = $this->data['scope'] ?? "";
            $this->is_snapshotuser = $this->data['is_snapshotuser'] ?? "";
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