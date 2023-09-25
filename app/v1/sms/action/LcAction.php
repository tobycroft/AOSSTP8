<?php

namespace app\v1\sms\action;

use app\v1\log\model\LogSmsModel;
use app\v1\sms\struct\SendStdErr;
use LCSms\Send;
use Throwable;

//jj-proj
class LcAction
{
    public static function SendText($proc, $ip, $type, $tag, $reverse_addr, $mch_id, $key, $phone, $text, $sign, $tpcode = null): SendStdErr
    {

        try {
            $name = $proc['name'];
            $ret = Send::full_text($reverse_addr, $mch_id, $key, $phone, $text, $sign);
            $success = false;
            if (strtolower($ret["code"]) == '00000') {
                $success = true;
            }
            $phones = explode(',', $phone);
            $datas = [];
            foreach ($phones as $p) {
                if (empty($p)) {
                    continue;
                }
//                $datas[] = [
//                    'name' => $name,
//                    'oss_type' => $type,
//                    'oss_tag' => $tag,
//                    'phone' => $p,
//                    'text' => $text,
//                    'raw' => json_encode($ret, 320),
//                    'ip' => $ip,
//                    'log' => $ret['msg'],
//                    'success' => $success,
//                    'error' => false,
//                ];
                LogSmsModel::create([
                    [
                        'name' => $name,
                        'oss_type' => $type,
                        'oss_tag' => $tag,
                        'phone' => $p,
                        'text' => $text,
                        'raw' => json_encode($ret, 320),
                        'ip' => $ip,
                        'log' => $ret['msg'],
                        'success' => $success,
                        'error' => false,
                    ]
                ]);
            }
            $log = new LogSmsModel();
            $ia = $log->insertAll($datas);
            if (!$ia) {
                return new SendStdErr(0, $datas, "数据库错误");
            }
            if ($success) {
                return new SendStdErr(0, null, $ret['msg']);
            } else {
                return new SendStdErr(200, $ret, $ret['msg']);

            }
        } catch (Throwable $e) {
            LogSmsModel::create([
                "oss_type" => $type,
                'name' => $name,
                "oss_tag" => $tag,
                "phone" => $phone,
                "text" => $text,
                'ip' => $ip,
                "log" => $e->getMessage(),
                "raw" => $e->getTraceAsString(),
                'success' => false,
                'error' => true,]);
            return new SendStdErr(500, null, $e->getMessage());
        }
    }

    public static function SendCode($reverse_addr, $type, $tag, $mch_id, $key, $phone, array|string $text, string $sign, $tpcode): SendStdErr
    {

        try {
            $ret = Send::code($reverse_addr, $mch_id, $key, $phone, $text, $sign, $tpcode);
            $success = false;
            if (strtolower($ret["code"]) == '00000') {
                $success = true;
            }

            LogSmsModel::create([
                'oss_type' => $type,
                'oss_tag' => $tag,
                'phone' => $phone,
                'text' => $text,
                'raw' => json_encode($ret, 320),
                'log' => $ret['msg'],
                'success' => $success,
                'error' => false,
            ]);
            if ($success) {
                return new SendStdErr(0, null, $ret['msg']);
            } else {
                return new SendStdErr(200, null, $ret['msg']);
            }
        } catch (Throwable $e) {
            LogSmsModel::create(["oss_type" => $type,
                "oss_tag" => $tag,
                "phone" => $phone,
                "text" => $text,
                "log" => $e->getMessage(),
                "raw" => $e->getTraceAsString(),
                'success' => false,
                'error' => true,]);
            return new SendStdErr(500, null, $e->getMessage());
        }
    }

}