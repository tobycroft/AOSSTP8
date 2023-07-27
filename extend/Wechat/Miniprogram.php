<?php

namespace Wechat;

use Net;
use Wechat\WechatRet\GetAccessToken;
use Wechat\WechatRet\WxaCode\GenerateScheme;
use Wechat\WechatRet\WxaCode\GetUnlimited;
use Wechat\WechatRet\WxaCode\GetUserPhoneNumber;
use Wechat\WechatRet\WxaCode\Jscode2Session;

class Miniprogram extends WechatUrl
{
    protected static $Base = "https://api.weixin.qq.com";

    public static function getBase()
    {
        return self::$Base;
    }

    public static function getAccessToken(string $appid, $secret, $grant_type = "client_credential"): GetAccessToken
    {
        return new GetAccessToken(
            Net::PostJson(self::$Base . self::$getAccessToken,
                [
                    "appid" => $appid,
                    "secret" => $secret,
                    "grant_type" => $grant_type,
                ]
            )
        );
    }

    public static function getWxaCodeUnlimit(string $access_token, $scene, $page, $width, $env_version = "release"): GetUnlimited
    {
        return new GetUnlimited(Net::PostJson(self::$Base . self::$getUnlimited,
            [
                "access_token" => $access_token
            ],
            [
                "scene" => $scene,
                "page" => $page,
                'check_path' => false,
                "width" => $width,
                "env_version" => $env_version,
            ]
        ));
    }

    public static function jscode2session(string $appid, $secret, $js_code, $grant_type): Jscode2Session
    {
        return new Jscode2Session(Net::PostJson(self::$Base . self::$jscode2session,
            [
                "appid" => $appid,
                "secret" => $secret,
                "js_code" => $js_code,
                "grant_type" => $grant_type,
            ]
        ));
    }

    public static function getuserphonenumber(string $access_token, $code): GetUserPhoneNumber
    {
        return new GetUserPhoneNumber(Net::PostJson(self::$Base . self::$getuserphonenumber,
            [
                "access_token" => $access_token
            ],
            [
                "code" => $code
            ]
        ));
    }

    public static function generatescheme(string $access_token, $path, $query, bool $is_expire = true, int $expire_interval = 179): GenerateScheme
    {
        return new GenerateScheme(Net::PostJson(self::$Base . self::$generatescheme,
            [
                "access_token" => $access_token
            ],
            [
                "jump_wxa" => [
                    "path" => $path,
                    "query" => $query
                ],
                "is_expire" => $is_expire,
                "expire_type" => 1,
                "expire_interval" => $expire_interval
            ]
        ));
    }

}
