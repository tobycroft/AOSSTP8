<?php

namespace LCSms;

use think\Exception;

class Send
{

    public static function full_text($reverse_addr, $mch_id, $key, $phone_nums, $contents, $sign): array
    {
        //普通发送示例：
        $time_stamp = self::getmicrotime();
        //发送的URL
        $url = $reverse_addr . '/sms/Service/group';
        //发送数据
        $data = [];
        $data['mch_id'] = $mch_id;                      //账号唯一标识
        $data['contents'] = $contents;                         //发送内容
        $data['tga_id'] = $sign;                           //短信签名ID
        $data['phone_nums'] = $phone_nums;                        //手机号码，多个用逗号‘,’隔开，最多1000个
        $data['time_stamp'] = $time_stamp;              //请求时间戳（13位）
        $data['sign'] = strtoupper(md5($mch_id . '&' . $time_stamp . '&' . $key)); //签名

        //$data['send_time'] = '';                        //预设发送时间（可不传）
        //$data['notify_url'] = '';                       //推送通知地址（可不传）
        //$data['user_data'] = '';                        //自定义数据，只能由1-50个数字或字母组成（可不传）
        //CURL请求
        $back = self::post($url, $data);
        //输出结果
        return json_decode($back, 1);
    }

    protected static function getmicrotime()
    {
        list($t1, $t2) = explode(' ', microtime());
        return (float)sprintf('%.0f', (floatval($t1) + floatval($t2)) * 1000);
    }

    /**
     * @throws Exception
     */
    protected static function post($url, $postData, $option = FALSE)
    {
        if (!is_array($postData)) {
            return FALSE;
        }
        //初始化curl
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);    //>设置请求地址
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); //>设置为返回请求内容

        if ($option) {
            //>默认以数组发送,当option = TRUR则以key=value&key=value的形式发送
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded')); //>设置HEADER
            $postData = http_build_query($postData);
        }

        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 5000);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

        if (!(strpos($url, 'https') === FALSE)) {
            //>设置SSLs
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        }
        $response = curl_exec($ch);  //>运行curl
        if ($response === false) {
            if (curl_errno($ch) == CURLE_OPERATION_TIMEDOUT) {
                throw new Exception('政务云短信平台超时');
            }
        }
        if (empty($response)) {
            if (curl_errno($ch) == CURLE_OPERATION_TIMEDOUT) {
                throw new Exception('政务云短信平台无返回');
            }
        }
        curl_close($ch);        //>关闭curl
        return $response;
    }

    public static function code($reverse_addr, $mch_id, $key, $phone_num, $code, $sign, $tpcode): array
    {

        //验证码/有变量模版发送示例：
        //发送前请先添加短信模版
        $time_stamp = self::getmicrotime();
        $url = $reverse_addr . '/sms/Service/codemsg';  //发送的URL

        //发送数据
        $data = [];
        $data['mch_id'] = $mch_id;  //账号唯一标识
        $data['phone_num'] = $phone_num; //手机号码，只能发送一个
        $data['tga_id'] = $sign;   //短信签名ID
        $data['tmp_id'] = $tpcode;   //短信模版ID

        //有带替换参数的通知短信或推广短信示例
        //$arr = ['替换数据1','替换数据2','替换数据3','替换数据4'];   //多变量示例
        //$data['contents'] = json_encode($arr);

        ////验证码示例
        $arr = [$code];
        $data['contents'] = json_encode($arr);  //模版替换内容以JONS的格式传送

        $data['time_stamp'] = $time_stamp;  //请求时间戳（13位）
        $data['sign'] = strtoupper(md5($mch_id . '&' . $time_stamp . '&' . $key)); //签名
        //$data['send_time'] = '';  //预设发送时间（可不传）
        //$data['notify_url'] = ''; //推送通知地址（可不传）
        //$data['user_data'] = '';  //自定义数据，只能由1-50个数字或字母组成（可不传）
        //CURL请求
        $back = self::post($url, $data);
//        var_dump($back);
        //输出结果
        return json_decode($back, 1);
    }

}