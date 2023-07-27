<?php

namespace Wechat;

use Net;
use Wechat\WechatRet\Template\UniformSend;

class KefuMessage extends Miniprogram
{

    public string $access_token;

    protected array $send = [];

    public function __construct(string $access_token, $touser)
    {
        $this->access_token = $access_token;
        $this->send['touser'] = $touser;
    }

    public function text($content)
    {
        $this->send['msgtype'] = __FUNCTION__;
        $this->send[__FUNCTION__] = [
            "content" => $content
        ];
    }

    public function image($media_id)
    {
        $this->send['msgtype'] = __FUNCTION__;
        $this->send[__FUNCTION__] = [
            'media_id' => $media_id
        ];
    }

    public function voice($media_id)
    {
        $this->send['msgtype'] = __FUNCTION__;
        $this->send[__FUNCTION__] = [
            'media_id' => $media_id
        ];
    }

    public function video($media_id, $thumb_media_id, $title, $description)
    {
        $this->send['msgtype'] = __FUNCTION__;
        $this->send[__FUNCTION__] = [
            'media_id' => $media_id,
            'thumb_media_id' => $thumb_media_id,
            'title' => $title,
            'description' => $description,
        ];
    }

    public function music($title, $description, $musicurl, $hqmusicurl, $thumb_media_id)
    {
        $this->send['msgtype'] = __FUNCTION__;
        $this->send[__FUNCTION__] = [
            'title' => $title,
            'description' => $description,
            'musicurl' => $musicurl,
            'hqmusicurl' => $hqmusicurl,
            'thumb_media_id' => $thumb_media_id,
        ];
    }

    public function send(): UniformSend
    {

        return new UniformSend(Net::PostJson(self::$Base . self::$message_send,
            [
                'access_token' => $this->access_token,
            ],
            $this->send
        ));
    }
}