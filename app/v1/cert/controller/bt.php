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
        $this->site = new Site($certs["api"], $certs["key"], './');
    }

    public function test()
    {
        return json_encode($this->site->getList(), 320);
    }

    public function getlist()
    {
        \Ret::Success(0, $this->site->getList()['data']);
    }

    public function pullssl()
    {
        $this->site->pullSSL();
    }

    public function autossl()
    {

    }

    public function setssl()
    {
//        $this->site->setSSL(1, $siteName, $key, $csr);
    }
}