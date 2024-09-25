<?php

namespace app\v1\cert\controller;

use app\v1\cert\action\SslAction;
use app\v1\cert\model\CertModel;
use app\v1\cert\model\CertUrlModel;
use app\v1\cert\model\CertWebsiteModel;
use BaseController\CommonController;
use Exception;
use Input;
use yixinba\Bt\Site;

class bt extends CommonController
{
    public $cert;
    public Site $site;

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
        return json_encode($this->site->getList(), 320);
    }

    public function getlist()
    {

    }


    public function pullssl()
    {
        $name = Input::Get('cert');
        try {
            $ssl = SslAction::updatessl($this->cert['tag'], $name);
            \Ret::Success(0, $ssl, '证书获取成功');
        } catch (Exception $e) {
            \Ret::Fail('500', null, $e->getMessage());
        }
    }

    public function setssl()
    {
        $name = Input::Get('cert');
        $website = Input::Get('website');
        try {
            $ssl = SslAction::updatessl($this->cert['tag'], $name);
        } catch (Exception $e) {
            \Ret::Fail('500', null, $e->getMessage());
        }
        $site = CertWebsiteModel::where('website', $website)->where('cert_url_tag', $name)->where('status', 1)->find();
        if (!$site) {
            \Ret::Fail(404, null, "项目中没有该站点，请先在自动更新库中添加本站点");
        }
        $this->site = new Site($site['api'], $site['key'], './');
        $ret = $this->site->setSSL(1, $website, $ssl['key'], $ssl['crt']);
        if ($ret) {
            \Ret::Success(0, $ret);
        } else {
            \Ret::Fail(500, $ret);
        }

    }

    public function autossl()
    {
        $name = Input::Get('cert');
        try {
            $ssl = SslAction::updatessl($this->cert['tag'], $name);
        } catch (Exception $e) {
            \Ret::Fail('500', null, $e->getMessage());
        }
        $cert = CertUrlModel::where('tag', $this->cert['tag'])->where('cert', $name)->find();
        if (!$cert) {
            \Ret::Fail(404, null, "未找到证书项目");
        }
        if ($cert['auto'] != 1) {
            \Ret::Fail(401, null, "本证书自动下发功能不可用");
        }
        $sites = CertWebsiteModel::where('cert_url_tag', $name)->where('status', 1)->select();
        foreach ($sites as $site) {
            $site = new Site($site['api'], $site['key'], './');
            $ret = $site->setSSL(1, $site['website'], $ssl['key'], $ssl['crt']);
            if (!$ret) {
                \Ret::Fail(500, $ret);
            }
        }
        \Ret::Success();
    }
}