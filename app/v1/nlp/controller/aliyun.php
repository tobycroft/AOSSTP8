<?php

namespace app\v1\nlp\controller;


use AlibabaCloud\Alinlp\Alinlp;
use AlibabaCloud\Client\Config\Config;

class aliyun
{
    public function index()
    {


        $config = new Config();
// 阿里云账号AccessKey拥有所有API的访问权限，风险很高。强烈建议您创建并使用RAM用户进行API访问或日常运维，请登录RAM控制台创建RAM用户。
// 此处以把AccessKey和AccessKeySecret保存在环境变量为例说明。您也可以根据业务需要，保存到配置文件里。
// 强烈建议不要把AccessKey和AccessKeySecret保存到代码里，会存在密钥泄漏风险
        $config->accessKeyId = getenv('NLP_AK_ENV');
        $config->accessKeySecret = getenv('NLP_SK_ENV');
        $config->regionId = 'cn-hangzhou';
        $config->endpoint = 'alinlp.cn-hangzhou.aliyuncs.com';
        $client = new Alinlp($config);
        $request = new GetNerChEcomRequest();
        $request->serviceCode = 'alinlp';
        $request->text = '电动多功能磨浆机';

        try {
            $response = $client->getNerChEcom($request);
            $json_string = json_encode($response->body, JSON_UNESCAPED_UNICODE);
            echo $json_string;
        } catch (TeaUnableRetryError $e) {
            var_dump($e->getMessage());
            var_dump($e->getErrorInfo());
            var_dump($e->getLastException());
            var_dump($e->getLastRequest());
        }
    }
}