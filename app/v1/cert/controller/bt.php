<?php

namespace app\v1\cert\controller;

use app\v1\cert\model\CertModel;
use app\v1\cert\model\CertUrlModel;
use app\v1\cert\model\CertWebsiteModel;
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

    private function updatessl($name)
    {
        $cert_url = CertUrlModel::where('tag', $this->cert['tag'])->where('cert', $name)->find();
        if (!$cert_url) {
            \Ret::Fail('404', null, '未找到证书项目');
        }
        $url_cert = file_get_contents($cert_url['url_crt']);
        $url_key = file_get_contents($cert_url['url_key']);
        if (empty($url_key) || empty($url_cert)) {
            \Ret::Fail('402', null, '证书获取失败');
        }
        \Ret::Fail(500, CertUrlModel::where('tag', $this->cert['tag'])->where('cert', $name)->update(['publickey' => $url_cert, 'privatekey' => $url_key]));
    }

    public function pullssl()
    {
        $name = Input::Get('cert');
        $this->updatessl($name);
        \Ret::Success(0, null, '证书获取成功');
    }

    public function autossl()
    {
        $name = Input::Get('cert');
        $cert_url = CertUrlModel::where('tag', $this->cert['tag'])->where('cert', $name)->find();
        if (!$cert_url) {
            \Ret::Fail("404", null, "未找到证书项目");
        }
        $url_cert = file_get_contents($cert_url['url_crt']);
        $url_key = file_get_contents($cert_url['url_key']);
        CertWebsiteModel::where('cert_url_tag', $name);
    }

    public function setssl()
    {
//        $this->site->setSSL(1, $siteName, $key, $csr);
    }
}