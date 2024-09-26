<?php

namespace app\v1\cert\action;

use app\v1\cert\model\CertUrlModel;
use app\v1\cert\model\CertWebsiteModel;
use think\Exception;
use yixinba\Bt\Base;

class MailAction
{
    const setCert = '/plugin?action=a&name=mail_sys&s=set_mail_certificate_multiple';
    const getDomainList = '/plugin?action=a&name=mail_sys&s=get_domains';

    public static function updatessl(string $cert_name): array|null
    {
        $cert_url = CertUrlModel::where('cert', $cert_name)->find();
        if (!$cert_url) {
            throw new Exception("未找到证书项目");
        }
        $url_cert = file_get_contents($cert_url['url_crt']);
        $url_key = file_get_contents($cert_url['url_key']);
        if (empty($url_key) || empty($url_cert)) {
            throw new Exception("证书获取失败");
        }
        CertUrlModel::where('cert', $cert_name)->update(['publickey' => $url_cert, 'privatekey' => $url_key]);
        return [
            'crt' => $url_cert,
            'key' => $url_key,
            'remark' => $cert_url['remark'],
        ];
    }

    public static function updateMailListWhichHadSSL($bt_api, $bt_key): array
    {
        $bt_site = new Base($bt_api, $bt_key, './');
        //p: 1
        //size: 10
        $post = [
            'p' => 1,
            'size' => 10000
        ];
        $ret = $bt_site->httpPostCookie(self::getDomainList, $post, 10);
        $data = [];
        $insertData = [];
        $certNames = CertUrlModel::column('cert');
        $siteNames = CertWebsiteModel::whereIn('cert_name', $certNames)->column('website');

        foreach ($ret['data'] as $site) {
            if ($site['ssl'] !== -1) {
                if (isset($site['ssl']['subject'])) {
                    if (in_array($site['ssl']['subject'], $certNames)) {
                        if (!in_array($site['name'], $siteNames)) {
                            $insertData[] = [
                                'website' => $site['name'],
                                'api' => $bt_api,
                                'key' => $bt_key,
                                'cert_name' => $site['ssl']['subject'],
                                'status' => 1,
                            ];
                        } else {
                            $data[] = [
                                'name' => $site['name'],
                                'ssl' => $site['ssl']['subject'],
                                'site_ssl' => $site['site_ssl']
                            ];
                        }
                    }
                }
            }
        }
        if (!empty($insertData)) {
            CertWebsiteModel::insertAll($insertData);
        }
        return $data;
    }


}