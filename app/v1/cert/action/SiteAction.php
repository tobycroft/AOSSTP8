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
    /**
     * 从域名中提取二级域名（主域名）
     * 例如：124.tuuz.ltd → tuuz.ltd, aria.aerofsx.com → aerofsx.com
     */
    public static function extractMainDomain(string $domain): string
    {
        $parts = explode('.', $domain);
        $count = count($parts);
        if ($count <= 2) {
            return $domain;
        }
        return $parts[$count - 2] . '.' . $parts[$count - 1];
    }

    /**
     * 规整域名用于比对：去空白、统一小写、剥离通配符前缀与结尾的点
     * 例如：*.aerofsx.com → aerofsx.com
     */
    public static function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        if (str_starts_with($domain, '*.')) {
            $domain = substr($domain, 2);
        }
        return rtrim($domain, '.');
    }

    /**
     * 判断证书是否覆盖指定根域名
     * 命中两种情况：证书名称与根域名相同（example.com）、
     * 证书为该根域名下的子域名（shop.example.com）
     */
    public static function certCoversRoot(string $cert, string $root): bool
    {
        $cert = self::normalizeDomain($cert);
        $root = self::normalizeDomain($root);
        if ($cert === '' || $root === '') {
            return false;
        }
        if ($cert === $root) {
            return true;
        }
        return str_ends_with($cert, '.' . $root);
    }

    /**
     * 解析站点归属的证书名称
     *
     * 优先到证书URL(ao_cert_url)中查找覆盖该站点根域名的证书，命中则使用对应证书名称；
     * 未命中时回退为该站点的根域名。同名证书优先于子域名证书。
     *
     * @param string $domain 站点域名
     * @param array|null $certNames 证书URL中的证书名称列表，为空时自动读取
     * @return string 证书名称
     */
    public static function resolveCertName(string $domain, ?array $certNames = null): string
    {
        $root = self::extractMainDomain($domain);

        if ($certNames === null) {
            $certNames = CertUrlModel::column('cert');
        }
        $certNames = array_values(array_filter(array_map('strval', (array)$certNames), function ($item) {
            return $item !== '';
        }));

        if (empty($certNames)) {
            return $root;
        }

        $matched = null;
        foreach ($certNames as $cert) {
            if (!self::certCoversRoot($cert, $root)) {
                continue;
            }
            if (self::normalizeDomain($cert) === self::normalizeDomain($root)) {
                return $cert;
            }
            if ($matched === null) {
                $matched = $cert;
            }
        }

        return $matched ?? $root;
    }

    /**
     * 获取证书名称在证书站点表中可能的取值
     *
     * 兼容历史上写入的「根域名」与新写入的「证书名称」两种数据，避免下发时匹配不到站点
     *
     * @param string $certName 证书URL中的证书名称
     * @return array cert_name 取值列表
     */
    public static function certNameCandidates(string $certName): array
    {
        $candidates = [
            $certName,
            self::normalizeDomain($certName),
            self::extractMainDomain($certName),
        ];

        $result = [];
        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && !in_array($candidate, $result, true)) {
                $result[] = $candidate;
            }
        }

        return $result;
    }

    public static function updateSiteListWhichHadSSL($bt_api, $bt_key): array
    {
        $bt_site = new Site($bt_api, $bt_key, './');
        $ret = $bt_site->getList();
        if ($ret === false) {
            throw new Exception('BT API调用失败: ' . $bt_site->getError());
        }
        if ($ret === null) {
            return [];
        }
        if (!isset($ret['data'])) {
            throw new Exception('返回数据异常: ' . json_encode($ret));
        }
        $data = [];
        $insertData = [];
        $certNames = CertUrlModel::column('cert');
        $siteNames = CertWebsiteModel::where('type', 'web')->column('website');

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
                                'cert_name' => self::resolveCertName($site['name'], $certNames),
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

    /**
     * 获取站点域名列表
     *
     * 从宝塔面板获取所有站点的域名列表
     *
     * @param string $bt_api 宝塔API地址
     * @param string $bt_key 宝塔API密钥
     * @return array 返回所有域名数组
     * @throws Exception 当宝塔API调用失败或返回数据异常时抛出异常
     */
    public static function getDomainList($bt_api, $bt_key): array
    {
        $bt_site = new Site($bt_api, $bt_key, './');
        $ret = $bt_site->getDomainListV2(null);
        if ($ret === false) {
            throw new Exception('BT API调用失败: ' . $bt_site->getError());
        }
        if ($ret === null || !isset($ret['message']['data'])) {
            return [];
        }

        $domains = [];
        foreach ($ret['message']['data'] as $site) {
            $domains[] = $site['name'];
        }

        return $domains;
    }
}