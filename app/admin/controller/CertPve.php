<?php

namespace app\admin\controller;

use app\admin\model\AdminCertPveModel;
use app\admin\model\AdminCertUrlModel;
use app\admin\service\CertPveService;
use app\admin\utils\AdminAuth;
use BaseController\CommonController;
use Exception;
use Input;
use Ret;

class CertPve extends CommonController
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

        $model = new AdminCertPveModel();
        $list = $model->order('id', 'desc')->paginate($limit, false, ['page' => $page]);

        $currentPage = (int)$list->currentPage();
        $total = $list->total();
        $totalPages = max(1, (int)ceil($total / $limit));

        $items = [];
        foreach ($list as $item) {
            $items[] = [
                'id' => $item['id'],
                'api' => $item['api'],
                'node' => $item['node'],
                'remark' => $item['remark'],
                'token_id' => $item['token_id'],
                'cert_name' => $item['cert_name'],
                'has_secret' => (string)$item['token_secret'] !== '',
                'verify_tls' => $item['verify_tls'],
                'restart_proxy' => $item['restart_proxy'],
                'status' => $item['status'],
                'last_deploy_time' => $item['last_deploy_time'] ?: '-',
                'last_deploy_status' => intval($item['last_deploy_status']),
                'last_error' => $item['last_error'],
            ];
        }

        $certs = AdminCertUrlModel::order('id', 'desc')->column('cert');

        return $this->renderPage('cert_pve/index', [
            'list' => $items,
            'certs' => $certs,
            'page' => $currentPage,
            'total' => $total,
            'totalPages' => $totalPages,
            'prevPage' => max(1, $currentPage - 1),
            'nextPage' => min($totalPages, $currentPage + 1),
        ], 'Proxmox VE', 'cert', 'cert_pve');
    }

    private function create()
    {
        try {
            $ret = CertPveService::create($this->payload());
        } catch (Exception $e) {
            $this->fail($e);
        }

        Ret::Success(0, $ret, '创建成功');
    }

    private function update()
    {
        try {
            CertPveService::update(Input::PostInt('id'), $this->payload());
        } catch (Exception $e) {
            $this->fail($e);
        }

        Ret::Success(0, [], '更新成功');
    }

    private function delete()
    {
        try {
            CertPveService::delete(intval(request()->delete('id')));
        } catch (Exception $e) {
            $this->fail($e);
        }

        Ret::Success(0, [], '删除成功');
    }

    public function testConnection()
    {
        $id = Input::PostInt('id');

        try {
            $ret = CertPveService::testConnection($id);
        } catch (Exception $e) {
            $this->fail($e);
        }

        Ret::Success(0, $ret, '连接成功');
    }

    public function deploy()
    {
        $id = Input::PostInt('id');

        try {
            $ret = CertPveService::deploy($id);
        } catch (Exception $e) {
            $this->fail($e);
        }

        Ret::Success(0, $ret, '证书下发成功');
    }

    public function deployAll()
    {
        try {
            $ret = CertPveService::deployAll();
        } catch (Exception $e) {
            $this->fail($e);
        }

        Ret::Success(0, $ret, '一键下发完成');
    }

    public function toggleStatus()
    {
        $id = Input::PostInt('id');

        try {
            $status = CertPveService::toggleStatus($id);
        } catch (Exception $e) {
            $this->fail($e);
        }

        $statusText = $status == 1 ? '启用' : '禁用';
        Ret::Success(0, ['status' => $status], "状态已切换为{$statusText}");
    }

    public function batchDelete()
    {
        $ids = request()->post('ids');
        if (empty($ids)) {
            Ret::Fail(400, null, '缺少参数[ids]');
        }

        $ids = json_decode((string)$ids, true);
        if (!is_array($ids) || empty($ids)) {
            Ret::Fail(400, null, '参数格式错误');
        }

        try {
            $count = CertPveService::batchDelete($ids);
        } catch (Exception $e) {
            $this->fail($e);
        }

        Ret::Success(0, ['count' => $count], "已删除 {$count} 条记录");
    }

    /**
     * 查看节点绑定的证书内容
     */
    public function certificate()
    {
        $id = Input::PostInt('id');

        try {
            $ret = CertPveService::certificate($id);
        } catch (Exception $e) {
            $this->fail($e);
        }

        Ret::Success(0, $ret);
    }

    /**
     * 读取 PUT/POST 表单数据
     */
    private function payload(): array
    {
        $isPut = request()->method() === 'PUT';
        $read = function (string $key, string $default) use ($isPut) {
            return $isPut
                ? request()->put($key, $default)
                : request()->post($key, $default);
        };

        return [
            'api' => $read('api', ''),
            'node' => $read('node', ''),
            'remark' => $read('remark', ''),
            'token_id' => $read('token_id', ''),
            'token_secret' => $read('token_secret', ''),
            'cert_name' => $read('cert_name', ''),
            'verify_tls' => $read('verify_tls', '0'),
            'restart_proxy' => $read('restart_proxy', '1'),
            'status' => $read('status', '1'),
        ];
    }

    /**
     * 把业务异常转换为 JSON 失败响应
     */
    private function fail(Exception $e): void
    {
        $code = intval($e->getCode());
        if ($code < 100 || $code > 599) {
            $code = 500;
        }

        Ret::Fail($code, null, $e->getMessage());
    }
}
