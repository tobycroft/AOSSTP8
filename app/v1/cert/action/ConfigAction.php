<?php

namespace app\v1\cert\action;

use think\Exception;
use yixinba\Bt\Base;

/**
 * 配置操作类
 * 用于处理宝塔面板的SSL证书配置
 */
class ConfigAction
{
    /**
     * 保存面板SSL证书的API端点
     */
    const setPanelSSL = '/config?action=SavePanelSSL';

    /**
     * 保存面板SSL证书
     * 
     * 调用宝塔面板API，为面板设置SSL证书
     * 
     * @param string $bt_api 宝塔API地址
     * @param string $bt_key 宝塔API密钥
     * @param string $csr 证书内容
     * @param string $key 私钥内容
     * @return mixed 返回宝塔面板的响应结果
     */
    public static function savePanelSSL($bt_api, $bt_key, $csr, $key)
    {
        $bt_site = new Base($bt_api, $bt_key, './');
        $post = [
            'csr' => $csr,
            'key' => $key,
        ];
        return $bt_site->httpPostCookie(self::setPanelSSL, $post, 15);
    }
}