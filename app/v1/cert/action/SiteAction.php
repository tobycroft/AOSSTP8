<?php

namespace app\v1\cert\action;

use app\v1\cert\model\CertUrlModel;
use app\v1\cert\model\CertWebsiteModel;
use think\Exception;
use tobycroft\Bt\Site;

/**
 * 站点操作类
 * 用于处理SSL证书更新和站点列表同步
 */
class SiteAction
{
    /**
     * 更新SSL证书
     * 
     * @param string $cert_name 证书名称
     * @return array|null 返回包含证书内容的信息数组，格式为：
     *                     [
     *                         'crt' => string,  // 证书内容
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
            'crt' => $url_cert,
            'key' => $url_key,
            'remark' => $cert_url['remark'],
        ];
    }

    /**
     * 更新已配置SSL的站点列表
     * 
     * 从宝塔面板获取站点列表，筛选出已配置SSL且证书匹配的站点，
     * 将新发现的站点插入数据库，并返回已存在的站点信息
     * 
     * @param string $bt_api 宝塔API地址
     * @param string $bt_key 宝塔API密钥
     * @return array 返回已存在的站点信息数组，每个元素包含：
     *               [
     *                   'name' => string,      // 站点名称
     *                   'ssl' => string,       // SSL证书主题
     *                   'site_ssl' => mixed   // 站点SSL状态
     *               ]
     * @throws Exception 当宝塔API调用失败或返回数据异常时抛出异常
     */
    public static function updateSiteListWhichHadSSL($bt_api, $bt_key): array
    {
        $bt_site = new Site($bt_api, $bt_key, './');
        try {
            $ret = $bt_site->getList();
            if (!isset($ret['data'])) {
                throw new Exception(json_encode('返回故障：' . $ret));
            }
        } catch (Exception $e) {
            throw new Exception('BT故障：' . $e);
        }
        $data = [];
        $insertData = [];
        $certNames = CertUrlModel::column('cert');
        $siteNames = CertWebsiteModel::whereIn('cert_name', $certNames)->where('type', 'web')->column('website');

        foreach ($ret['data'] as $site) {
            if ($site['ssl'] !== -1) {
                if (isset($site['ssl']['subject'])) {
                    if (in_array($site['ssl']['subject'], $certNames)) {
                        if (!in_array($site['name'], $siteNames)) {
                            $insertData[] = [
                                'website' => $site['name'],
                                'type' => 'web',
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