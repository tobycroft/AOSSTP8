<?php

namespace app\v1\cert\controller;

use app\v1\cert\model\CertModel;
use BaseController\CommonController;
use Input;
use yixinba\Bt\Base;
use yixinba\Bt\Site;

class bt extends CommonController
{
    public Base $bt_base;

    public function initialize()
    {
        parent::initialize();
        $tag = Input::Get('tag');
        $certs = CertModel::where('tag', $tag)->find();
        if (!$certs) {
            \Ret::Fail("404", null, "未找到证书项目");
        }
        $bt_base = new Site($certs["url"], $certs["key"], './');
        \Ret::Success(0, $bt_base->getList(''));
    }

    public function test()
    {

    }
}