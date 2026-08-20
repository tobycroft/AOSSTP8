<?php

namespace app\v1\cert\controller;

use app\v1\cert\action\MailAction;
use app\v1\cert\model\CertLogModel;
use app\v1\cert\model\CertUrlModel;
use app\v1\cert\model\CertWebsiteModel;
use Input;
use think\Exception;
use tobycroft\Bt\Base;

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
        $ret = MailAction::updateMailSSL($this->cert['bt_api'], $this->cert['bt_key'], $site['website'], $ssl['csr'], $ssl['key']);
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
            MailAction::updateMailListWhichHadSSL($this->cert['bt_api'], $this->cert['bt_key']);
        } catch (Exception $e) {
        }
        try {
            $ssl = MailAction::updatessl($name);
        } catch (Exception $e) {
            \Ret::Fail('500', null, $e->getMessage());
        }

        $sites = CertWebsiteModel::where('type', 'mail')->where('cert_name', $name)->where('status', 1)->select();
        $rets = [
            'success' => 0,
            'fail' => 0,
            'detail' => [],
        ];
        foreach ($sites as $site) {
            $catchError = null;
            try {
                $bt_base = new Base($site['api'], $site['key'], './');
                $ret = $bt_base->httpPostCookie(MailAction::setCert, [
                    'domain' => $site['website'],
                    'csr' => $ssl['csr'],
                    'key' => $ssl['key'],
                    'act' => 'add',
                ], 15);
            } catch (Exception $e) {
                $ret = false;
                $catchError = $e->getMessage();
            }
            if ($ret) {
                CertLogModel::create([
                    'appname' => $this->cert['appname'],
                    'type' => $site['type'],
                    'success' => 1,
                    'website' => $site['website'],
                    'recv' => json_encode($ret, 320),
                ]);
                $rets['success']++;
                $rets['detail'][] = [
                    'type' => $site['type'],
                    'website' => $site['website'],
                    'success' => true,
                ];
            } else {
                CertLogModel::create([
                    'appname' => $this->cert['appname'],
                    'type' => $site['type'],
                    'success' => 0,
                    'website' => $site['website'],
                    'recv' => json_encode($ret, 320),
                ]);
                $rets['fail']++;
                $error = $catchError ?? $bt_base->getError() ?: '邮件SSL部署失败';
                if ($ret === null) {
                    CertWebsiteModel::where('id', $site['id'])->update(['status' => 0]);
                }
                $rets['detail'][] = [
                    'type' => $site['type'],
                    'website' => $site['website'],
                    'success' => false,
                    'error' => $error,
                ];
            }
        }
        \Ret::Success(0, $rets);
    }

}