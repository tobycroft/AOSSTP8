<?php

namespace app\v1\cert\action;

use app\v1\cert\model\CertUrlModel;
use think\Exception;

class SslAction
{


    public static function updatessl($tag, $name): array
    {
        $cert_url = CertUrlModel::where('tag', $tag)->where('cert', $name)->find();
        if (!$cert_url) {
            throw new Exception("未找到证书项目");
        }
        $url_cert = file_get_contents($cert_url['url_crt']);
        $url_key = file_get_contents($cert_url['url_key']);
        if (empty($url_key) || empty($url_cert)) {
            throw new Exception("证书获取失败");
        }
        CertUrlModel::where('tag', $tag)->where('cert', $name)->update(['publickey' => $url_cert, 'privatekey' => $url_key])
        return [
            'publickey' => $url_cert,
            'privatekey' => $url_key
        ];
    }

}