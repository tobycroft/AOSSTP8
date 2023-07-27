<?php

namespace Shineupay;

/****006支付应用类 OK****/
class Shineupay
{

    /***配置参数***/
    var $merchantId; //商户编号
    var $secret_key;  //商户密钥
    var $pay_notify_url;  //代收回调域名
    var $pay_callbackUrl; //代收跳转域名
    var $df_notify_url; //代付回调域名
    var $tixian;        //提现密钥

    /****构造函数*****/
    public function __construct()
    {
        $this->merchantId = "BFURJK9KB0N45734"; //商户编号;
        $this->secret_key = 'ae88b583d79b4ccab28c63592e05ca46'; //商户密钥
        $this->pay_notify_url = 'http://upload.tuuz.cc:81/payment/index/daishou_huitiao'; //代收回调域名
        $this->pay_callbackUrl = 'http://upload.tuuz.cc:81/payment/index/tiaozhuan'; //代收跳转域名
        $this->df_notify_url = 'http://upload.tuuz.cc:81/payment/index/daifu_huitiao';   //代付回调域名
        $this->tixian = 'BGGKE8D0KLXC4396';                 //提现密钥
    }

    public function create_order($order, $currency, $money, $user_id, $remark)
    {
        $key = $this->secret_key; //商户密钥
        $url = "https://testgateway.shineupay.com/pay/create"; //网关地址
        $params["orderId"] = $order;                           //订单号
//        $params["receiveCurrency"] =$currency ;
        $params["amount"] = $money; //支付金额
        $getMillisecond = $this->getMillisecond(); //毫秒时间戳
        $params["details"] = $remark; //支付商品说明
        $params["userId"] = $user_id;    //商户会员标识
        $params["notifyUrl"] = $this->pay_notify_url;    //商户会员标识
        $params["redirectUrl"] = $this->pay_callbackUrl;    //商户会员标识
        $data['body'] = $params;
        $data['merchantId'] = $this->merchantId;
        $data['timestamp'] = "$getMillisecond";
        $sign = $this->sign($key, $data, $getMillisecond);
        $headers = array("Content-type: application/json;charset=UTF-8", "Accept: application/json", "Cache-Control: no-cache", "Pragma: no-cache", "Api-Sign:$sign");
        $json = $this->curlPost($url, $data, 5, $headers, $getMillisecond);
        $res = json_decode($json, true);

        if ($res['body']['contentType'] == '0') {
            return array('status' => true, 'pay_url' => $res['body']['content'], 'trans_sn' => $res['body']['transactionId'], 'msg' => '创建成功');
        } else {
            return array('status' => false, 'msg' => '创建失败');
        }
    }

    //查询代收订单

    public
    function getMillisecond()
    {
        list($microsecond, $time) = explode(' ', microtime()); //' '中间是一个空格
        return (float)sprintf('%.0f', (floatval($microsecond) + floatval($time)) * 1000);
    }

    //回调代收订单

    public
    function sign($key, $params)
    {
        $params = array_filter($params);
        $str = json_encode($params) . "|" . $key;
        $sign = MD5($str);
        return $sign;
    }

    public
    function curlPost($url, $data, $timeout, $headers, $getMillisecond)
    {
        $data = json_encode($data);
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_POST, 1); // 发送一个常规的Post请求
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
        if (!empty($data)) {
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        }
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        $output = curl_exec($curl);
        if (curl_errno($curl)) {
            echo 'Errno' . curl_error($curl); //捕抓异常
        }
        curl_close($curl);
        return $output;
    }

    public function pay_check($trans_sn)
    {
        $key = $this->secret_key; //商户密钥
        $url = "https://testgateway.shineupay.com/pay/query"; //网关地址
        $params["orderId"] = $trans_sn;    //订单号
        $params["details"] = "details"; //支付商品说明
        $params['userId'] = "57899";
        $getMillisecond = $this->getMillisecond(); //毫秒时间戳
        $data['body'] = $params;
        $data['merchantId'] = $this->merchantId;
        $data['timestamp'] = "$getMillisecond";
        $sign = $this->sign($key, $data);
        $headers = array("Content-type: application/json;charset=UTF-8", "Accept: application/json", "Cache-Control: no-cache", "Pragma: no-cache", "Api-Sign:$sign");
        $json = $this->curlPost($url, $data, 5, $headers, $getMillisecond);
        $res = json_decode($json, true);
        if ($res['body']['status'] == 1) {
            $code = array('code' => '200', 'trans_sn' => $res['data']['platOrderId'], 'status' => "PAY_SUCCESS", 'msg' => '支付成功');
        } else if ($res['body']['status'] == 0) {
            $code = array('code' => '200', 'trans_sn' => $res['data']['platOrderId'], 'status' => "create_order", 'msg' => '创建订单');
        } else if ($res['body']['status'] == 2) {
            $code = array('code' => '0', 'msg' => '支付失败');
        } else if ($res['body']['status'] == 3) {
            $code = array('code' => '200', 'trans_sn' => $res['data']['platOrderId'], 'status' => "PAY_ING", 'msg' => '正在支付中');
        }
    }

    public function pay_notify()
    {
        $contents = file_get_contents('php://input');
        $secret_key = $this->secret_key; //商户密钥
        $str = $contents . "|" . $secret_key;
        $signr = MD5($str);
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        $sign = $headers['Api-Sign'];
        if ($sign != $signr) {
            return ["status" => false, "msg" => "签名出错"];
        }
        $post = json_decode($contents, true);
        $params['orderId'] = $post['body']['orderId']; //商户单号
        $params['platformOrderId'] = $post['body']['platformOrderId']; //第三方单号
        $status = $params['status'] = $post['body']['status']; //支付状态     0	尚未付款，订单已创建1	付款成功2	付款失败，请重新支付（二维码过期，超时付款等）3	付款中，表示等待付款中91	金额异常，支付订单金额出现异常
        if ($post['status'] == 2) $params['message'] = $post['body']['message']; //消息通知
        $params['amount'] = $post['body']['amount']; //支付金额
        $params['payType'] = $post['body']['payType']; //支付通道
        if ($signr == $sign) {
            if ($status == 1) {
                return ["status" => true, "data" => $params, "orderId" => $params['orderId']];
            } else {
                return ["status" => false, "data" => $params];
            }
        } else {
            return ["status" => false, "msg" => $params['message']];
        }
    }

    /****查询钱包余额****/
    public function check_balance()
    {
        $key = $this->secret_key; //商户密钥
        $url = "https://testgateway.shineupay.com/withdraw/balance"; //网关地址
        $getMillisecond = $this->getMillisecond(); //毫秒时间戳
        $data['body'] = (object)null;
        $data['merchantId'] = $this->merchantId;
        $data['timestamp'] = "$getMillisecond";
        $sign = $this->sign($key, $data);
        $headers = array("Content-type: application/json;charset=UTF-8", "Accept: application/json", "Cache-Control: no-cache", "Pragma: no-cache", "Api-Sign:$sign");
        $json = $this->curlPost($url, $data, 5, $headers, $getMillisecond);
        $res = json_decode($json, true);
        if ($res['status'] == '0') {
            return array('status' => true, 'balance' => $res['body']['balance'], "freeze_balance" => $res["body"]["frozenBalance"]);
        } else {
            return ["status" => false, "msg" => ""];
        }
    }


//获取毫秒数

    /****创建代付订单****/
    public function create_daifu($orderId, $amount100, $currency, $prodName, $phone, $bank_user_name, $bank_cardno, $bank_branch_name, $bank_email, $address)
    {
//组装数据
        $params['version'] = "1.0.0"; //默认传1.0.0
        $params['advPasswordMd5'] = md5($this->tixian); //string	是	交易密码的md5值（32位小写），详看交易密码说明
        $params['orderId'] = $orderId; //string	是	商户订单编号，请确保唯一，最多允许200个字符
        $params['amount'] = $amount100 / 100; //float	是	提现金额
        $params['details'] = "details"; //string		提现说明
        $params['notifyUrl'] = $this->df_notify_url; //string		异步通知地址
        $params['receiveCurrency'] = $currency; //string		收款人收款货币 印度传INR 巴西传BRL
        $params['settlementCurrency'] = $currency;       //string		订单结算币种 INR,BRL,IUSDT,BUSDT
        $params['prodName'] = $prodName; //string		代付类型编码

//银行卡信息
        $bankCardInfo["userName"] = $bank_user_name; //string	是	银行卡的持卡人
        $bankCardInfo['bankCardNumber'] = $bank_cardno; //string	是	银行卡的卡号
        $bankCardInfo['IFSC'] = $bank_branch_name; //String 	是	银行卡持卡人IFSC码
        $bankCardInfo['phone'] = $phone; //string	是	银行卡的预留手机号
        $bankCardInfo['email'] = $bank_email; //String	是	用户邮箱
        $bankCardInfo['province'] = ""; //String	否	银行所在省名称
        $bankCardInfo['city'] = "";     //String	否	银行所在城市名称
        $bankCardInfo['address'] = $address; //String	否	所在地区名称
        $params['extInfo'] = $bankCardInfo; //收款人银行信息信息

        $key = $this->secret_key; //商户密钥
        $url = "https://testgateway.shineupay.com/v2/Withdraw/Create"; //网关地址
        $getMillisecond = $this->getMillisecond(); //毫秒时间戳
        $data['body'] = $params;
        $data['merchantId'] = $this->merchantId;
        $data['timestamp'] = "$getMillisecond";
        $sign = $this->sign($key, $data);
        $headers = array("Content-type: application/json;charset=UTF-8", "Accept: application/json", "Cache-Control: no-cache", "Pragma: no-cache", "Api-Sign:$sign");
        $json = $this->curlPost($url, $data, 5, $headers, $getMillisecond);
        $res = json_decode($json, true);
        echo $json;
        if ($res['status'] == 0) {
            return array('status' => true, 'msg' => $res['message']);
        } else {
            return array('status' => false, 'msg' => $res['message']);
        }
    }


//签名

    /****查询代付订单****/
    public
    function check_daifu($orderId)
    {
        $key = $this->secret_key; //商户密钥
        $url = "https://testgateway.shineupay.com/withdraw/query"; //网关地址
        $params["orderId"] = $orderId; //订单号
        $getMillisecond = $this->getMillisecond(); //毫秒时间戳
        $data['body'] = $params;
        $data['merchantId'] = $this->merchantId;
        $data['timestamp'] = "$getMillisecond";
        $sign = $this->sign($key, $data);
        $headers = array("Content-type: application/json;charset=UTF-8", "Accept: application/json", "Cache-Control: no-cache", "Pragma: no-cache", "Api-Sign:$sign");
        $json = $this->curlPost($url, $data, 5, $headers, $getMillisecond);
        $res = json_decode($json, true);
        if ($res['status'] == 1) {
            return ["status" => true, "msg" => "支付成功"];
        } else if ($res['status'] == 0) {
            return ["status" => false, "msg" => "创建订单"];
        } else if ($res['status'] == 2) {
            return ["status" => false, "msg" => "查询失败"];
        }
    }


//asc排序

    /****回调代付订单****/
    public
    function daifu_huitiao()
    {
        $contents = file_get_contents('php://input');
        $secret_key = $this->secret_key; //商户密钥
        $str = $contents . "|" . $secret_key;
        $signr = MD5($str);
        $post = json_decode($contents, true);
        $params['orderId'] = $post['body']['orderId']; //商户单号
        $platformOrderId = $params['platformOrderId'] = $post['body']['platformOrderId']; //第三方单号
        $params['status'] = $post['body']['status']; //支付状态     0	尚未付款，订单已创建1	付款成功2	付款失败，请重新支付（二维码过期，超时付款等）3	付款中，表示等待付款中91	金额异常，支付订单金额出现异常
        if ($post['status'] == 2) $params['message'] = $post['body']['message']; //消息通知
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        $sign = $headers['Api-Sign'];
        if ($signr == $sign) {
            if ($post['status'] != 1) {
                if ($params['status'] == 1) {
                    return ["status" => false, "data" => $params, "orderId" => $params['orderId']];
                }
                if ($params['status'] == 2) {
                    return ["status" => false, "data" => $params, "orderId" => $params['orderId']];
                }
            }
            return ["status" => true, "data" => $params, "orderId" => $params['orderId']];
        } else {
            return ["status" => false, "msg" => "查询失败"];
        }
    }

//post请求方式

    public
    function asc_sort($params = array())
    {
        if (!empty($params)) {
            $p = ksort($params);
            if ($p) {
                $str = '';
                foreach ($params as $k => $val) {
                    $str .= $k . '=' . $val . '&';
                }
                $strs = rtrim($str, '&');
                return $strs;
            }
        }
        return false;
    }
}
