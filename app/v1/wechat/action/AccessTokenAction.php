<?php

namespace app\v1\wechat\action;

use app\v1\wechat\model\WechatModel;
use Wechat\Miniprogram;

class AccessTokenAction
{

    protected mixed $token;
    protected mixed $appid;
    protected mixed $appsecret;
    protected string $access_token;

    public function __construct($token, $appid, $appsecret)
    {
        $this->token = $token;
        $this->appid = $appid;
        $this->appsecret = $appsecret;
    }

    public function get_access_token()
    {
        return $this->access_token;
    }

    public function auto_error_code($errcode)
    {
        switch ($errcode) {
            case 40001:
                $this->refresh_token();
                break;


            default:
                break;
        }
    }

    public function refresh_token(): string|null
    {
        $data = Miniprogram::getAccessToken($this->appid, $this->appsecret);
        if ($data->isSuccess()) {
            $this->access_token = $data->access_token;
            if (!WechatModel::where('project', $this->token)->data(
                [
                    'access_token' => $data->access_token,
                    'expire_after' => date('Y-m-d H:i:s', $data->expires_in + time() - 600)
                ]
            )->update()) {
                return '数据库更新错误';
            }
        } else {
            return 'accesstoken刷新失败';
        }
        return null;
    }
}