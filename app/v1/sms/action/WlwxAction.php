<?php

namespace app\v1\sms\action;

use app\v1\log\model\LogSmsModel;
use app\v1\sms\struct\SendStdErr;
use Throwable;
use WlwxSMS\Send;

//jj-proj
class WlwxAction
{
    public static function SendCode($proc, $ip, $type, $tag, $code, $password, $cust_code, $content, $destMobiles): SendStdErr
    {
        $content = str_replace('{$code}', $code, $content);
        return self::SendText($proc, $ip, $type, $tag, $password, $cust_code, $content, $destMobiles);
    }

    public static function SendText($proc, $ip, $type, $tag, $password, $cust_code, $content, $destMobiles): SendStdErr
    {

        try {
            $name = $proc['name'];
            $ret = Send::full_text($password, $cust_code, $content, $destMobiles);
            $success = false;
            //1012 ins balance
            if (strtolower($ret["respCode"]) == '0') {
                $success = true;
            }

            LogSmsModel::create([
                'name' => $name,
                'oss_type' => $type,
                'oss_tag' => $tag,
                'phone' => $destMobiles,
                'text' => $content,
                'raw' => json_encode($ret, 320),
                'ip' => $ip,
                'log' => $ret['respMsg'],
                'success' => $success,
                'error' => false,
            ]);
            if ($success) {
                return new SendStdErr(0, null, $ret['respMsg']);
            } else {
                return new SendStdErr(200, $ret, $ret['respMsg']);

            }
        } catch (Throwable $e) {
            LogSmsModel::create(["oss_type" => $type,
                'name' => $name,
                "oss_tag" => $tag,
                "phone" => $destMobiles,
                "text" => $content,
                'ip' => $ip,
                "log" => $e->getMessage(),
                "raw" => $e->getTraceAsString(),
                'success' => false,
                'error' => true,]);
            return new SendStdErr(500, null, $e->getMessage());
        }
    }

}