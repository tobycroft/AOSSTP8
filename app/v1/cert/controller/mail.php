<?php

namespace app\v1\cert\controller;

use app\v1\cert\action\MailAction;
use app\v1\cert\action\SiteAction;
use app\v1\cert\model\CertWebsiteModel;
use Input;
use think\Exception;
use yixinba\Bt\Base;

class mail extends bt
{


    public function initialize()
    {
        parent::initialize();
    }

    public function getlist()
    {
        $data = MailAction::updateMailListWhichHadSSL($this->cert['bt_api'], $this->cert['bt_key']);
        \Ret::Success(0, $data);
    }

    public function setssl()
    {
        $name = Input::Get('cert');
        $website = Input::Get('website');
        $site = CertWebsiteModel::where('type', 'mail')->where('website', $website)->where('cert_name', $name)->where('status', 1)->find();
        if (!$site) {
            \Ret::Fail(404, null, '项目中没有该站点，请先在自动更新库中添加本站点');
        }
        try {
            $ssl = MailAction::updatessl($name);
        } catch (Exception $e) {
            \Ret::Fail('500', null, $e->getMessage());
        }
        $bt_site = new Base($site['api'], $site['key'], './');
        $post = [
            'domain' => $site['website'],
            'csr' => $ssl['crt'],
            'key' => $ssl['key'],
            'act' => 'add',
        ];
        $ret = $bt_site->httpPostCookie(MailAction::setCert, $post, 15);
        if ($ret) {
            \Ret::Success(0, $ret);
        } else {
            \Ret::Fail(500, $ret);
        }

    }

}