<?php

namespace Wechat;

use Net;
use Wechat\WechatRet\GetTicket;

class Ticket extends Miniprogram
{
    public static function getTicket(string $access_token, $type = 'jsapi'): GetTicket
    {
        return new GetTicket(
            Net::PostJson(self::$Base . self::$getTicket,
                [
                    'access_token' => $access_token,
                    'type' => $type,
                ]
            )
        );
    }
}