<?php

namespace app\v1\cert\controller;

use app\v1\cert\model\CertModel;
use app\v1\hook\model\HookModel;
use BaseController\CommonController;
use yixinba\Bt\Base;

class bt extends CommonController
{
    public $bt_base;

    public function initialize()
    {
        parent::initialize();
        $tag = Input::Get('tag');
        $certs = CertModel::where('tag', $tag)->select();
        if (!$certs) {
            \Ret::Fail("404", null, "未找到证书项目");
        }
//        $this->bt_base = new Base($panel, $key, $cookiePath)
        
    }

    public function test()
    {
    }
}