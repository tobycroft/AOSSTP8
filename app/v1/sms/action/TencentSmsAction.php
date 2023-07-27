<?php

namespace app\v1\sms\action;

use app\v1\log\model\LogSmsModel;
use app\v1\sms\struct\SendStdErr;
use QcloudSms\SmsSingleSenderFix;


// 导入要请求接口对应的Request类

// 导入可选配置类

class TencentSmsAction
{
    public static function Send($proc, string $ip, mixed $type, $tag, $appid, $appkey, int $quhao, string|array $phone, string $text, $smsSign, $templateId): SendStdErr
    {

        try {
            $name = $proc['name'];

            $ssender = new SmsSingleSenderFix($appid, $appkey);
            $params = json_decode($text, 1);
            $result = $ssender->sendWithParam($quhao, $phone, $templateId, $params, $smsSign, '', '');
            $ret = json_decode($result);
            $success = false;
            if ($ret->result === 0) {
                $success = true;
            }
            LogSmsModel::create([
                'name' => $name,
                'oss_type' => $type,
                'oss_tag' => $tag,
                'phone' => $phone,
                'text' => $text,
                'raw' => $result,
                'ip' => $ip,
                'log' => $ret->errmsg,
                'success' => $success,
                'error' => false,
            ]);
            if ($success) {
                return new SendStdErr(0, null, $ret->errmsg);
            } else {
                return new SendStdErr(200, $result, $ret->errmsg);
            }
        } catch (\Exception $e) {
            LogSmsModel::create([
                'name' => $name,
                'oss_type' => $type,
                'oss_tag' => $tag,
                'phone' => $phone,
                'text' => $text,
                'log' => $e->getMessage(),
                'raw' => $e->getTraceAsString(),
                'success' => false,
                'error' => true,
            ]);
            return new SendStdErr(500, null, $e->getMessage());
        }
    }
}