<?php

namespace app\v1\sms\action;

use app\v1\sms\model\SmsAliyunModel;
use app\v1\sms\model\SmsBlackListModel;
use app\v1\sms\model\SmsInterceptModel;
use app\v1\sms\model\SmsLcModel;
use app\v1\sms\model\SmsTencentModel;
use app\v1\sms\model\SmsWlwxModel;
use app\v1\sms\struct\SendStdErr;
use Ret;

class SendAction
{

    //AutoSend:返回错误
    public static function AutoSend($proc, $quhao, $phone, string $text, $ip): SendStdErr|null
    {
        $black = SmsBlackListModel::where("phone", $phone)->find();
        if ($black) {
            $intercept = SmsInterceptModel::where("phone", $phone)->find();
            if ($intercept) {
                SmsInterceptModel::where('phone', $phone)->setInc('num', 1);
            } else {
                SmsInterceptModel::create(['phone' => $phone, 'num' => 1]);
            }
            Ret::Fail(403, null, "号码暂时进入黑名单");
        }
        //'none','aliyun','tencent','ihuyi','zz253','lc','wlwx'
        switch ($proc["sms_type"]) {
            case "aliyun":
                $data = SmsAliyunModel::where("tag", $proc["sms_tag"])->findOrEmpty();
                if ($data) {
                    return AliyunAction::Send($proc, $ip, $proc['sms_type'], $proc['sms_tag'], $data['accessid'], $data['accesskey'], $phone, $text, $data['sign'], $data['tpcode']);
                }
                Ret::Fail(408, null, '未找到aliyun平台对应模板');
                break;

            case "tencent":
                $data = SmsTencentModel::where('tag', $proc['sms_tag'])->findOrEmpty();
                if ($data) {
                    return TencentSmsAction::Send($proc, $ip, $proc['sms_type'], $proc['sms_tag'], $data['appid'], $data['appkey'], $quhao, $phone, $text, $data['sign'], $data['tplid']);
                }
                Ret::Fail(408, null, '未找到tencent平台对应模板');
                break;

            case "ihuyi":
                Ret::Fail(408, null, '2');
                break;

            case "zz253":
                Ret::Fail(408, null, '3');
                break;

            case "lc":
                $data = SmsLcModel::where('tag', $proc['sms_tag'])->findOrEmpty();
                if ($data) {
//                    if (strlen($text) <= 6) {
//                        return LcAction::SendCode($proc['sms_type'], $proc['sms_tag'], $data['reverse_addr'], $data['mch_id'], $data['key'], $phone, $text, $data['sign'], $data['tpcode']);
//                    } else {
                    return LcAction::SendText($proc, $ip, $proc['sms_type'], $proc['sms_tag'], $data['reverse_addr'], $data['mch_id'], $data['key'], $phone, $text, $data['sign'], $data['tpcode']);
//                    }
                }
                Ret::Fail(408, null, '未找到LC平台对应模板');
                break;

            case "wlwx":
                //https://smsapp.wlwx.com/sendSms
                $data = SmsWlwxModel::where('tag', $proc['sms_tag'])->findOrEmpty();
                if ($data) {
                    return WlwxAction::SendCode($proc, $ip, $proc['sms_type'], $proc['sms_tag'], $text, $data["password"], $data["cust_code"], $data["template"], $phone);
                }
                break;

            default:
                Ret::Fail(408, null, "项目没有对应的短信方案或模板");
                break;
        }
        return null;
    }
}