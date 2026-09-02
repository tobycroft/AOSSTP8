<?php

namespace app\admin\controller;

use app\admin\model\AdminCertUrlModel;
use app\admin\model\AdminCertModel;
use app\admin\model\AdminCertWebsiteModel;
use app\admin\model\AdminCertLogModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use app\v1\cert\action\SiteAction;
use app\v1\cert\action\ConfigAction;
use app\v1\cert\action\MailAction;
use BaseController\CommonController;
use Input;
use Ret;
use think\Exception;
use tobycroft\Bt\Site;
use tobycroft\Bt\Base;

class CertUrl extends CommonController
{
    public function initialize()
    {
        parent::initialize();
        AdminAuth::requireLogin();
    }

    public function index()
    {
        $method = request()->method();
        switch ($method) {
            case 'GET':
                return $this->page();
            case 'POST':
                return $this->update();
            case 'PUT':
                return $this->create();
            case 'DELETE':
                return $this->delete();
        }
        Ret::Fail(405, null, '不支持的请求方法');
    }

    private function page()
    {
        $page = input('get.page', 1, 'intval');
        $limit = 15;

        $model = new AdminCertUrlModel();
        $list = $model->order('id', 'desc')->paginate($limit, false, ['page' => $page]);

        $currentPage = (int)$list->currentPage();
        $total = $list->total();
        $totalPages = max(1, (int)ceil($total / $limit));

        $items = [];
        foreach ($list as $item) {
            $items[] = [
                'id' => $item['id'],
                'cert' => $item['cert'],
                'url_crt' => $item['url_crt'],
                'url_key' => $item['url_key'],
                'remark' => $item['remark'],
                'auto' => $item['auto'],
                'auto_text' => $item['auto'] == 1 ? '是' : '否',
            ];
        }

        $pagination = Layout::pagination($currentPage, $totalPages, '/admin/cert_url');

        return $this->renderPage('cert_url/index', [
            'list' => $items,
            'pagination' => $pagination,
        ], '证书URL', 'cert', 'cert_url');
    }

    private function create()
    {
        $cert = request()->put('cert');
        $url_crt = request()->put('url_crt', '');
        $url_key = request()->put('url_key', '');
        $remark = request()->put('remark', '');
        $auto = intval(request()->put('auto', '0')) ?: 0;

        if (empty($cert)) {
            Ret::Fail(400, null, '证书名称不能为空');
        }

        $model = AdminCertUrlModel::create([
            'cert' => $cert,
            'url_crt' => $url_crt,
            'url_key' => $url_key,
            'remark' => $remark,
            'auto' => $auto,
        ]);

        Ret::Success(0, ['id' => $model['id']], '创建成功');
    }

    private function update()
    {
        $id = Input::PostInt('id');
        $model = new AdminCertUrlModel();
        $item = $model->findOrEmpty($id);

        if ($item->isEmpty()) {
            Ret::Fail(404, null, '记录不存在');
        }

        $data = [];
        if (request()->has('cert', 'post')) {
            $data['cert'] = Input::Post('cert');
        }
        if (request()->has('url_crt', 'post')) {
            $data['url_crt'] = Input::Post('url_crt', false);
        }
        if (request()->has('url_key', 'post')) {
            $data['url_key'] = Input::Post('url_key', false);
        }
        if (request()->has('remark', 'post')) {
            $data['remark'] = Input::Post('remark', false);
        }
        if (request()->has('auto', 'post')) {
            $data['auto'] = Input::PostInt('auto');
        }

        $item->save($data);
        Ret::Success(0, [], '更新成功');
    }

    private function delete()
    {
        $id = intval(request()->delete('id'));
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminCertUrlModel();
        $item = $model->findOrEmpty($id);

        if ($item->isEmpty()) {
            Ret::Fail(404, null, '记录不存在');
        }

        $item->delete();
        Ret::Success(0, [], '删除成功');
    }

    public function updateSSL()
    {
        $id = intval(request()->post('id'));
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminCertUrlModel();
        $item = $model->findOrEmpty($id);

        if ($item->isEmpty()) {
            Ret::Fail(404, null, '记录不存在');
        }

        try {
            $url_crt = $item['url_crt'];
            $url_key = $item['url_key'];

            if (empty($url_crt) || empty($url_key)) {
                Ret::Fail(400, null, 'CRT URL 或 KEY URL 为空');
            }

            $publickey = file_get_contents($url_crt);
            $privatekey = file_get_contents($url_key);

            if (empty($publickey) || empty($privatekey)) {
                Ret::Fail(500, null, '证书获取失败');
            }

            $item->save([
                'publickey' => $publickey,
                'privatekey' => $privatekey,
            ]);

            Ret::Success(0, [], '更新成功');
        } catch (Exception $e) {
            Ret::Fail(500, null, '证书获取失败: ' . $e->getMessage());
        }
    }

    public function updateAllSSL()
    {
        $urlModel = new AdminCertUrlModel();
        $items = $urlModel->where('id', '>', 0)->select();

        $rets = [
            'success' => 0,
            'fail' => 0,
            'detail' => [],
        ];

        foreach ($items as $item) {
            $url_crt = $item['url_crt'];
            $url_key = $item['url_key'];

            if (empty($url_crt) || empty($url_key)) {
                $rets['fail']++;
                $rets['detail'][] = [
                    'cert' => $item['cert'],
                    'success' => false,
                    'error' => 'CRT URL 或 KEY URL 为空',
                ];
                continue;
            }

            try {
                $publickey = file_get_contents($url_crt);
                $privatekey = file_get_contents($url_key);
            } catch (Exception $e) {
                $publickey = '';
                $privatekey = '';
            }

            if (empty($publickey) || empty($privatekey)) {
                $rets['fail']++;
                $rets['detail'][] = [
                    'cert' => $item['cert'],
                    'success' => false,
                    'error' => '证书获取失败',
                ];
                continue;
            }

            $item->save([
                'publickey' => $publickey,
                'privatekey' => $privatekey,
            ]);

            $rets['success']++;
            $rets['detail'][] = [
                'cert' => $item['cert'],
                'success' => true,
            ];
        }

        Ret::Success(0, $rets, '一键更新完成');
    }

    public function getKey()
    {
        $id = Input::PostInt('id');

        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminCertUrlModel();
        $item = $model->findOrEmpty($id);

        if ($item->isEmpty()) {
            Ret::Fail(404, null, '记录不存在');
        }

        Ret::Success(0, [
            'publickey' => $item['publickey'] ?: '',
            'privatekey' => $item['privatekey'] ?: '',
        ]);
    }

    public function autoSSL()
    {
        $id = Input::PostInt('id');
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $urlModel = new AdminCertUrlModel();
        $item = $urlModel->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '记录不存在');
        }
        if ($item['auto'] != 1) {
            Ret::Fail(401, null, '本证书自动下发功能不可用');
        }

        $name = $item['cert'];

        $certModel = new AdminCertModel();
        $cert = $certModel->where('appname', $name)->where('status', 1)->find();

        if ($cert) {
            try {
                SiteAction::updateSiteListWhichHadSSL($cert['bt_api'], $cert['bt_key']);
            } catch (Exception $e) {
            }
        }

        $ssl = SiteAction::updatessl($name);

        $rets = [
            'success' => 0,
            'fail' => 0,
            'detail' => [],
        ];

        $sites = AdminCertWebsiteModel::where('type', 'web')
            ->where('cert_name', $name)
            ->where('status', 1)
            ->select();

        foreach ($sites as $site) {
            $bt_site = new Site($site['api'], $site['key'], './');
            $ret = $bt_site->setSSL(1, $site['website'], $ssl['key'], $ssl['crt']);
            if ($ret) {
                AdminCertLogModel::create([
                    'appname' => $name,
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
                AdminCertLogModel::create([
                    'appname' => $name,
                    'type' => $site['type'],
                    'success' => 0,
                    'website' => $site['website'],
                    'recv' => json_encode($ret, 320),
                ]);
                $rets['fail']++;
                $error = $bt_site->getError() ?: '未知错误';
                if ($ret === null) {
                    AdminCertWebsiteModel::where('id', $site['id'])->update(['status' => 0]);
                }
                $rets['detail'][] = [
                    'type' => $site['type'],
                    'website' => $site['website'],
                    'success' => false,
                    'error' => $error,
                ];
            }
        }

        $panelSites = AdminCertWebsiteModel::where('type', 'panel')
            ->where('cert_name', $name)
            ->where('status', 1)
            ->select();

        foreach ($panelSites as $site) {
            $catchError = null;
            $bt_base = null;
            try {
                $bt_base = new Base($site['api'], $site['key'], './');
                $ret = $bt_base->httpPostCookie(ConfigAction::setPanelSSL, [
                    'privateKey' => $ssl['key'],
                    'certPem' => $ssl['crt'],
                ], 15);
            } catch (Exception $e) {
                $ret = false;
                $catchError = $e->getMessage();
            }
            if ($ret) {
                AdminCertLogModel::create([
                    'appname' => $name,
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
                AdminCertLogModel::create([
                    'appname' => $name,
                    'type' => $site['type'],
                    'success' => 0,
                    'website' => $site['website'],
                    'recv' => json_encode($ret, 320),
                ]);
                $rets['fail']++;
                $error = $catchError ?? $bt_base->getError() ?: '面板SSL部署失败';
                if ($ret === null) {
                    AdminCertWebsiteModel::where('id', $site['id'])->update(['status' => 0]);
                }
                $rets['detail'][] = [
                    'type' => $site['type'],
                    'website' => $site['website'],
                    'success' => false,
                    'error' => $error,
                ];
            }
        }

        Ret::Success(0, $rets, '自动下发完成');
    }

    public function autoMailSSL()
    {
        $id = Input::PostInt('id');
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $urlModel = new AdminCertUrlModel();
        $item = $urlModel->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '记录不存在');
        }
        if ($item['auto'] != 1) {
            Ret::Fail(401, null, '本证书自动下发功能不可用');
        }

        $name = $item['cert'];

        $certModel = new AdminCertModel();
        $cert = $certModel->where('appname', $name)->where('status', 1)->find();

        if ($cert) {
            try {
                MailAction::updateMailListWhichHadSSL($cert['bt_api'], $cert['bt_key']);
            } catch (Exception $e) {
            }
        }

        $ssl = null;
        try {
            $ssl = MailAction::updatessl($name);
        } catch (Exception $e) {
            Ret::Fail(500, null, $e->getMessage());
        }

        $sites = AdminCertWebsiteModel::where('type', 'mail')
            ->where('cert_name', $name)
            ->where('status', 1)
            ->select();

        $rets = [
            'success' => 0,
            'fail' => 0,
            'detail' => [],
        ];

        foreach ($sites as $site) {
            $catchError = null;
            $bt_base = null;
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
                AdminCertLogModel::create([
                    'appname' => $name,
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
                AdminCertLogModel::create([
                    'appname' => $name,
                    'type' => $site['type'],
                    'success' => 0,
                    'website' => $site['website'],
                    'recv' => json_encode($ret, 320),
                ]);
                $rets['fail']++;
                $error = $catchError ?? $bt_base->getError() ?: '邮件SSL部署失败';
                if ($ret === null) {
                    AdminCertWebsiteModel::where('id', $site['id'])->update(['status' => 0]);
                }
                $rets['detail'][] = [
                    'type' => $site['type'],
                    'website' => $site['website'],
                    'success' => false,
                    'error' => $error,
                ];
            }
        }

        Ret::Success(0, $rets, '邮箱更新完成');
    }
}
