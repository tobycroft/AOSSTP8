<?php

namespace app\v1\cert\controller;

use app\v1\cert\action\MailAction;
use app\v1\cert\model\CertModel;
use app\v1\cert\model\CertWebsiteModel;
use BaseController\CommonController;
use Input;
use think\Exception;
use yixinba\Bt\Site;

class mail extends CommonController
{
    public $cert;

    public function initialize()
    {
        parent::initialize();
        $appname = Input::Get('appname');
        $appkey = Input::Get('appkey');
        $this->cert = CertModel::where('appname', $appname)->where('appkey', $appkey)->find();
        if (!$this->cert) {
            \Ret::Fail('404', null, '未找到证书项目');
        }
    }


    public function setssl()
    {
        $name = Input::Get('cert');
        $website = Input::Get('website');
        $site = CertWebsiteModel::where('type', 'mail')->where('website', $website)->where('cert_url_tag', $name)->where('status', 1)->find();
        if (!$site) {
            \Ret::Fail(404, null, '项目中没有该站点，请先在自动更新库中添加本站点');
        }
        try {
            $ssl = MailAction::updatessl($name);
        } catch (Exception $e) {
            \Ret::Fail('500', null, $e->getMessage());
        }
        $bt_site = new Site($site['api'], $site['key'], './');
        $ret = $bt_site->setSSL(1, $website, $ssl['key'], $ssl['crt']);
        if ($ret) {
            \Ret::Success(0, $ret);
        } else {
            \Ret::Fail(500, $ret);
        }

    }

}