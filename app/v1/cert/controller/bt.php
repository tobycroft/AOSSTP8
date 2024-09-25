<?php

namespace app\v1\cert\controller;

use app\v1\cert\action\SslAction;
use app\v1\cert\model\CertLogModel;
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

    public function initialize()
    {
        parent::initialize();
        $appname = Input::Get('appname');
        $appkey = Input::Get('appkey');
        $this->cert = CertModel::where('appname', $appname)->where('appkey', $appkey)->find();
        if (!$this->cert) {
            \Ret::Fail("404", null, "未找到证书项目");
        }
    }

    public function test()
    {

    }

    public function getlist()
    {
        $bt_site = new Site($this->cert['bt_api'], $this->cert['bt_key'], './');
        $ret = $bt_site->getList();
        $data = [];
        foreach ($ret['data'] as $site) {
            if ($site['ssl'] !== -1) {
                if (isset($site['ssl']['subject'])) {
                    if (CertUrlModel::where('cert', $site['ssl']['subject'])->find()) {
                        if (CertWebsiteModel::where('website', $site['name'])->find()) {
                            $data[] = [
                                'name' => $site['name'],
                                'ssl' => $site['ssl']['subject'],
                                'site_ssl' => $site['site_ssl']
                            ];
                        } else {
                            CertWebsiteModel::create([
                                'website' => $site['name'],
                                'api' => $this->cert['bt_api'],
                                'key' => $this->cert['bt_key'],
                                'cert_name' => $site['ssl']['subject'],
                                'status' => 1,
                            ]);
                        }
                    }
                }
            }
        }
        \Ret::Success(0, $data);
    }

    public function autofill()
    {
        $bt_site = new Site($this->cert['bt_api'], $this->cert['bt_key'], './');
        $ret = $bt_site->getList();
        $data = [];
        foreach ($ret['data'] as $site) {
            if ($site['ssl'] === -1)
                $data[] = [
                    'name' => $site['name'],
                    'ssl' => $site['ssl'],
                    'type' => gettype($site['ssl']),
                    'site_ssl' => $site['site_ssl']
                ];
        }
        \Ret::Success(0, $data);
    }

    public function pullssl()
    {
        $name = Input::Get('cert');
        try {
            $ssl = SslAction::updatessl($name);
            \Ret::Success(0, $ssl, '证书获取成功');
        } catch (Exception $e) {
            \Ret::Fail('500', null, $e->getMessage());
        }
    }

    public function setssl()
    {
        $name = Input::Get('cert');
        $website = Input::Get('website');
        $site = CertWebsiteModel::where('website', $website)->where('cert_url_tag', $name)->where('status', 1)->find();
        if (!$site) {
            \Ret::Fail(404, null, "项目中没有该站点，请先在自动更新库中添加本站点");
        }
        try {
            $ssl = SslAction::updatessl($name);
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

    public function autossl()
    {
        $name = Input::Get('cert');
        $cert = CertUrlModel::where('cert', $name)->find();
        if (!$cert) {
            \Ret::Fail(404, null, '未找到证书项目');
        }
        if ($cert['auto'] != 1) {
            \Ret::Fail(401, null, '本证书自动下发功能不可用');
        }
        try {
            $ssl = SslAction::updatessl($name);
        } catch (Exception $e) {
            \Ret::Fail('500', null, $e->getMessage());
        }

        $sites = CertWebsiteModel::where('cert_name', $name)->where('status', 1)->select();
        $rets = [
            'success' => 0,
            'fail' => 0
        ];
        foreach ($sites as $site) {
            $bt_site = new Site($site['api'], $site['key'], './');
            $ret = $bt_site->setSSL(1, $site['website'], $ssl['key'], $ssl['crt']);
            if ($ret) {
                CertLogModel::create([
                    'appname' => $this->cert['appname'],
                    'success' => 1,
                    'website' => $site['website'],
                    'recv' => json_encode($ret, 320),
                ]);
                $rets['success']++;
            } else {
                CertLogModel::create([
                    'appname' => $this->cert['appname'],
                    'success' => 0,
                    'website' => $site['website'],
                    'recv' => json_encode($ret, 320),
                ]);
                $rets['fail']++;
            }
        }
        \Ret::Success(0, $rets);
    }
}