<?php

namespace app\v1\cert\action;

use app\v1\cert\model\CertUrlModel;
use app\v1\cert\model\CertWebsiteModel;
use think\Exception;
use yixinba\Bt\Base;

/**
 * 邮件操作类
 * 用于处理邮件服务器的SSL证书更新和域名列表同步
 */
class MailAction
{
    /**
     * 设置邮件证书的API端点
     */
    const setCert = '/plugin?action=a&name=mail_sys&s=set_mail_certificate_multiple';
    
    /**
     * 获取域名列表的API端点
     */
    const getDomainList = '/plugin?action=a&name=mail_sys&s=get_domains';

    /**
     * 更新SSL证书
     * 
     * @param string $cert_name 证书名称
     * @return array|null 返回包含证书内容的信息数组，格式为：
     *                     [
     *                         'csr' => string,  // 证书内容
     *                         'key' => string,  // 私钥内容
     *                         'remark' => string // 备注
     *                     ]
     * @throws Exception 当未找到证书项目或证书获取失败时抛出异常
     */
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
            'csr' => $url_cert,
            'key' => $url_key,
            'remark' => $cert_url['remark'],
        ];
    }

    /**
     * 更新已配置SSL的邮件域名列表
     * 
     * 从邮件服务器获取域名列表，将新发现的域名插入数据库，
     * 并返回所有域名列表
     * 
     * @param string $bt_api 宝塔API地址
     * @param string $bt_key 宝塔API密钥
     * @return array 返回所有域名列表
     * @throws Exception 当邮件服务器返回数据异常时抛出异常
     */
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
//        \Ret::Success(0, $ret);
        if (!isset($ret['msg'])) {
            throw new Exception('MailServer返回的message列表为空');
        }
        if (!isset($ret['msg']['data'])) {
            throw new Exception('MailServer返回的data列表为空');
        }
        $data = $ret['msg']['data'];
        $insertData = [];
        $certNames = CertUrlModel::column('cert');
        $siteNames = CertWebsiteModel::whereIn('cert_name', $certNames)->where('type', 'mail')->column('website');
        $domains = [];

        foreach ($data as $site) {
            $domains[] = $site['domain'];
            if (!in_array($site['domain'], $siteNames)) {
                $insertData[] = [
                    'website' => $site['domain'],
                    'type' => 'mail',
                    'api' => $bt_api,
                    'key' => $bt_key,
                    'cert_name' => $site['domain'],
                    'status' => 1,
                ];
            }
        }
        if (!empty($insertData)) {
            CertWebsiteModel::insertAll($insertData);
        }
        return $domains;
    }

    /**
     * 更新邮件服务器的SSL证书
     * 
     * 调用邮件服务器API，为指定域名设置SSL证书
     * 
     * @param string $bt_api 宝塔API地址
     * @param string $bt_key 宝塔API密钥
     * @param string $website 域名
     * @param string $csr 证书内容
     * @param string $key 私钥内容
     * @return mixed 返回邮件服务器的响应结果
     */
    public static function updateMailSSL($bt_api, $bt_key, $website, $csr, $key)
    {
        $bt_site = new Base($bt_api, $bt_key, './');
        $post = [
            'domain' => $website,
            'csr' => $csr,
            'key' => $key,
            'act' => 'add',
        ];
        return $bt_site->httpPostCookie(self::setCert, $post, 15);
    }


}