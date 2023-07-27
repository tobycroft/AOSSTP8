<?php

namespace app\v1\sms\action;

use app\v1\log\model\LogSmsModel;
use app\v1\sms\model\SmsBlackListModel;
use app\v1\sms\struct\SendStdErr;
use Flc\Dysms\Client;
use Flc\Dysms\Request\SendSms;

class AliyunAction
{
    public static function Send($proc, $ip, $type, $tag, $accessid, $accesskey, $phone, $text, $sign, $tpcode): SendStdErr
    {
        $config = [
            'accessKeyId' => $accessid,
            'accessKeySecret' => $accesskey,
        ];
        try {
            $name = $proc['name'];
            $client = new Client($config);
            $sendSms = new SendSms();
            $sendSms->setPhoneNumbers($phone);
            $sendSms->setSignName($sign);
            $sendSms->setTemplateCode($tpcode);
            $sendSms->setTemplateParam(json_decode($text, 320));
//        $sendSms->setOutId('demo');
            $ret = $client->execute($sendSms);
            $success = false;
            if (strtolower($ret->Code) == "ok") {
                $success = true;
            } elseif ($ret->Code == "isv.BUSINESS_LIMIT_CONTROL") {
                $count = LogSmsModel::where("phone", $phone)
                    ->whereExp("date", ">current_date")
                    ->count();
                if ($count > $proc["sms_limit"]) {
                    SmsBlackListModel::create([
                        'name' => $name,
                        'phone' => $phone,
                    ]);
                }
            }
            LogSmsModel::create([
                'name' => $name,
                'oss_type' => $type,
                'oss_tag' => $tag,
                'phone' => $phone,
                'text' => $text,
                'raw' => json_encode($ret, 320),
                'ip' => $ip,
                'log' => $ret->Message,
                'success' => $success,
                'error' => false,
            ]);
            if ($success) {
                return new SendStdErr(0, null, $ret->Message);
            } else {
                return new SendStdErr(200, null, $ret->Message);
            }
        } catch (\Throwable $e) {
            LogSmsModel::create([
                'name' => $name,
                "oss_type" => $type,
                "oss_tag" => $tag,
                "phone" => $phone,
                "text" => $text,
                'ip' => $ip,
                "log" => $e->getMessage(),
                "raw" => $e->getTraceAsString(),
                'success' => false,
                'error' => true,
            ]);
            return new SendStdErr(500, null, $e->getMessage());
        }
    }
}