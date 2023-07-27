<?php

namespace Wechat\WechatRet;


class UserInfo
{
    public $response;
    public int $subscribe;
    public string $openid;
    public string $nickname;
    public int $sex;
    public string $language;
    public string $city;
    public string $province;
    public string $country;
    public string $headimgurl;
    public int $subscribe_time;
    public string $subscribe_scene;
    protected $data;
    protected mixed $error;
    protected int $errcode = 0;

    /*
     * {
    'subscribe': 1,
    'openid': 'oWboX6gK1VcGcIj5jG_XKEQVx4Fc',
    'nickname': '',
    'sex': 0,
    'language': 'zh_CN',
    'city': '',
    'province': '',
    'country': '',
    'headimgurl': '',
    'subscribe_time': 1668071043,
    'remark': '',
    'groupid': 0,
    'tagid_list': [],
    'subscribe_scene': 'ADD_SCENE_QR_CODE',
    'qr_scene': 0,
    'qr_scene_str': ''
}
     */

    public function __construct($json)
    {
        $this->response = $json;
        $data = json_decode($json, 1);
        if (isset($data['errmsg'])) {
            $this->error = $data['errmsg'];
            $this->errcode = $data['errcode'];
        } else {
            $this->data = $data;
            $this->subscribe = $this->data['subscribe'];
            $this->openid = $this->data['openid'];
            $this->nickname = $this->data['nickname'];
            $this->sex = $this->data['sex'];
            $this->language = $this->data['language'];

            $this->city = $this->data['city'];
            $this->province = $this->data['province'];
            $this->country = $this->data['country'];

            $this->headimgurl = $this->data['headimgurl'];
            $this->subscribe_time = $this->data['subscribe_time'];
            $this->subscribe_scene = $this->data['subscribe_scene'];
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

    public function getData()
    {
        return $this->data;
    }

    public function getErrcode(): int
    {
        return $this->errcode;
    }
}