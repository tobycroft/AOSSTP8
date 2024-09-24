<?php

namespace app\v1\cert\controller;

use app\v1\cert\model\CertModel;
use BaseController\CommonController;
use Input;
use yixinba\Bt\Site;

class bt extends CommonController
{
    public Site $site;

    public function initialize()
    {
        parent::initialize();
        $tag = Input::Get('tag');
        $certs = CertModel::where('tag', $tag)->find();
        if (!$certs) {
            \Ret::Fail("404", null, "未找到证书项目");
        }
        $this->site = new Site($certs["url"], $certs["key"], './');
    }

    public function test()
    {
        return json_encode($this->site->getList(), 320);
    }

    public function getlist()
    {
        return json_encode($this->site->getList()["data"], 320);
    }

    public function setssl()
    {
//        $this->site->setSSL(1, $siteName, $key, $csr);
    }
}