<?php

namespace Wechat;

use miniprogram_struct;
use Net;
use Wechat\WechatRet\GetAccessToken;
use Wechat\WechatRet\Offi\GetUnlimited;
use Wechat\WechatRet\Offi\SnsAuth;
use Wechat\WechatRet\Template\TemplateSend;
use Wechat\WechatRet\Template\UniformSend;
use Wechat\WechatRet\UserGet;
use Wechat\WechatRet\UserInfo;
use Wechat\WechatRet\WxaCode\OffiAccessToken;

class OfficialAccount extends Miniprogram
{

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

    public static function userlist(string $access_token, $next_openid): UserGet
    {
        return new UserGet(Net::PostJson(self::$Base . self::$user_get,
            [
                "access_token" => $access_token,
                "next_openid" => $next_openid,
            ]
        ));
    }

    public static function userinfo(string $access_token, $openid): UserInfo
    {
        return new UserInfo(Net::PostJson(self::$Base . self::$user_info,
            [
                "access_token" => $access_token,
                "openid" => $openid,
            ]
        ));
    }

    public static function uniform_send(string $access_token, $touser, $template_id, $url, $data): UniformSend
    {
        return new UniformSend(Net::PostJson(self::$Base . self::$uniform_send,
            [
                "access_token" => $access_token,
            ],
            [
                "touser" => $touser,
                "mp_template_msg" => [
//                    'appid' => $appid,
                    'template_id' => $template_id,
                    'url' => $url,
                    'data' => json_decode($data, 1),
                ],
            ]
        ));
    }

    public static function template_send(string $access_token, $touser, $template_id, $data, $url, miniprogram_struct $miniprogram_struct = null, $client_msg_id = null): TemplateSend
    {
        $send = [
            'touser' => $touser,
            'template_id' => $template_id,
            'url' => $url,
            'data' => json_decode($data, 1),
        ];
        if (!empty($miniprogram_struct)) {
            $send['miniprogram'] = [
                'appid' => $miniprogram_struct->appid,
                'pagepath' => $miniprogram_struct->pagepath,
            ];
        }
        if (!empty($client_msg_id)) {
            $send['client_msg_id'] = $client_msg_id;
        }
        return new TemplateSend(Net::PostJson(self::$Base . self::$template_send,
            [
                "access_token" => $access_token,
            ],
            $send
        ));
    }

    //user_getOpenid:获取用户openid
    public static function user_getOpenid(string $appid, $secret, $code, $grant_type): OffiAccessToken
    {
        return new OffiAccessToken(Net::PostJson(self::$Base . self::$offi_access_token,
            [
                'appid' => $appid,
                'secret' => $secret,
                'code' => $code,
                'grant_type' => $grant_type,
            ]
        ));
    }


    /*
     *
{
    'touser': 'OPENID',
    'template_id': 'ngqIpbwh8bUfcSsECmogfXcV14J0tQlEpBO27izEYtY',
    'url': 'http://weixin.qq.com/download',
    'topcolor': '#FF0000',
    'data': {
        'User': {
            'value': '黄先生',
            'color': '#173177'
        },
        'Date': {
            'value': '06月07日 19时24分',
            'color': '#173177'
        },
        'CardNumber': {
            'value': '0426',
            'color': '#173177'
        },
        'Type': {
            'value': '消费',
            'color': '#173177'
        },
        'Money': {
            'value': '人民币260.00元',
            'color': '#173177'
        },
        'DeadTime': {
            'value': '06月07日19时24分',
            'color': '#173177'
        },
        'Left': {
            'value': '6504.09',
            'color': '#173177'
        }
    }
}
     */

    public static function message_template(string $access_token, $openid, $template_id)
    {

    }


    public static function getQrSceneUnlimit(string $access_token, $scene): GetUnlimited
    {
        return new GetUnlimited(Net::PostJson(self::$Base . self::$getQrScene,
            [
                'access_token' => $access_token
            ],
            [
                'expire_seconds' => '2592000',
                'action_name' => 'QR_STR_SCENE',
                'action_info' => [
                    'scene' => [
                        'scene_str' => $scene
                    ]
                ],
            ]
        ));
    }


    public static function getQrSceneLimit(string $access_token, $scene): GetUnlimited
    {
        return new GetUnlimited(Net::PostJson(self::$Base . self::$getQrScene,
            [
                'access_token' => $access_token
            ],
            [
                'action_name' => 'QR_LIMIT_STR_SCENE',
                'action_info' => $scene,
            ]
        ));
    }

    public static function snsAuth(string $access_token, $openid): SnsAuth
    {
        return new SnsAuth(Net::PostJson(self::$Base . self::$getQrScene,
            [
                'access_token' => $access_token,
                'openid' => $openid,
            ],
        ));
    }


}

