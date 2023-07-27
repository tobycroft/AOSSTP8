<?php

namespace Wechat\WechatRet\Offi;

class GetUnlimited
{
    const ticket_url = 'https://mp.weixin.qq.com/cgi-bin/showqrcode?ticket=';
    public string $response;
    public string $ticket;
    public string $ticket_url;
    public int $expire_seconds;
    public string $url;
    public mixed $image;
    protected int $errcode = 0;
    private $error;

    public function __construct($json)
    {
        $this->response = $json;
        $data = json_decode($json, 1);
        if (isset($data['ticket']) && isset($data['expire_seconds']) && isset($data['url'])) {
            $this->ticket = $data["ticket"];
            $this->expire_seconds = $data["expire_seconds"];
            $this->url = $data["url"];
            $this->ticket_url = self::ticket_url . $this->ticket;
        } else {
            $this->error = $data['errmsg'];
            $this->errcode = $data['errcode'];
        }
    }

    /**
     * @return string
     */
    public function download_image()
    {
        if ($this->isSuccess()) {
            $this->image = file_get_contents($this->ticket_url);
        }
    }

    public function isSuccess()
    {
        if (isset($this->error) && $this->errcode != "0") {
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