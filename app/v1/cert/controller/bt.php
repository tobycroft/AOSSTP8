<?php

namespace app\v1\cert\controller;

use app\v1\cert\model\CertModel;
use BaseController\CommonController;
use Input;
use yixinba\Bt\Site;

class bt extends CommonController
{
    public $cert;

    public function initialize()
    {
        parent::initialize();
        $tag = Input::Get('tag');
        $key = Input::Get('key');
        $this->cert = CertModel::where('tag', $tag)->where('key', $key)->find();
        if (!$this->cert) {
            \Ret::Fail("404", null, "未找到证书项目");
        }
    }

    public function test()
    {
        $this->site = new Site($this->cert['api'], $this->cert['key'], './');
        return json_encode($this->site->getList(), 320);
    }

    public function getlist()
    {

    }

    public function pullssl()
    {

    }

    public function autossl()
    {

    }

    public function setssl()
    {
//        $this->site->setSSL(1, $siteName, $key, $csr);
    }
}