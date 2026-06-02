<?php

namespace app\v1\cert\action;

use think\Exception;
use yixinba\Bt\Base;

class ConfigAction
{
    const setPanelSSL = '/config?action=SavePanelSSL';

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