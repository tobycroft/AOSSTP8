<?php

namespace app\admin\controller;

use app\admin\model\AdminCertIkuaiModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use app\v1\cert\action\IkuaiAction;
use BaseController\CommonController;
use Exception;
use Input;
use Ret;

class CertIkuai extends CommonController
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

        $model = new AdminCertIkuaiModel();
        $total = $model->count();
        $totalPages = max(1, (int)ceil($total / $limit));

        $list = $model->order('id', 'desc')->page($page, $limit)->select();

        $items = [];
        foreach ($list as $item) {
            $items[] = [
                'id' => $item['id'],
                'api' => $item['api'],
                'cert_name' => $item['cert_name'],
                'username' => $item['username'],
                'sess_key' => $this->maskSessKey((string)$item['sess_key']),
                'last_login_time' => $item['last_login_time'] ?: '-',
                'expire_time' => $item['expire_time'] ?: '-',
                'last_error' => $item['last_error'],
                'status' => $item['status'],
            ];
        }

        $pagination = Layout::pagination($page, $totalPages, '/admin/cert_ikuai');

        return $this->renderPage('cert_ikuai/index', [
            'list' => $items,
            'pagination' => $pagination,
        ], '爱快路由器', 'cert', 'cert_ikuai');
    }

    private function create()
    {
        $api = trim(strval(request()->put('api')));
        $username = trim(strval(request()->put('username')));
        $password = strval(request()->put('password'));

        if ($api === '') {
            Ret::Fail(400, null, '路由器地址不能为空');
        }
        if ($username === '') {
            Ret::Fail(400, null, '登录用户名不能为空');
        }
        if ($password === '') {
            Ret::Fail(400, null, '登录密码不能为空');
        }

        // 复制新增时容易遗漏修改地址或用户名，提前给出提示避免唯一索引报错
        if (AdminCertIkuaiModel::where('api', $api)->where('username', $username)->find()) {
            Ret::Fail(400, null, '该路由器地址与用户名已存在，请修改后再保存');
        }

        $item = AdminCertIkuaiModel::create([
            'api' => $api,
            'cert_name' => trim(strval(request()->put('cert_name', ''))),
            'username' => $username,
            'password' => $password,
            'status' => intval(request()->put('status', '1')) ?: 1,
        ]);

        Ret::Success(0, ['id' => $item['id']], '创建成功');
    }

    private function update()
    {
        $id = Input::PostInt('id');
        $model = new AdminCertIkuaiModel();
        $item = $model->findOrEmpty($id);

        if ($item->isEmpty()) {
            Ret::Fail(404, null, '记录不存在');
        }

        $data = [];
        if (request()->has('api', 'post')) {
            $api = trim(Input::Post('api', false));
            if ($api === '') {
                Ret::Fail(400, null, '路由器地址不能为空');
            }
            $data['api'] = $api;
        }
        if (request()->has('cert_name', 'post')) {
            $data['cert_name'] = trim(Input::Post('cert_name', false));
        }
        if (request()->has('username', 'post')) {
            $username = trim(Input::Post('username', false));
            if ($username === '') {
                Ret::Fail(400, null, '登录用户名不能为空');
            }
            $data['username'] = $username;
        }
        // 密码留空表示不修改
        if (request()->has('password', 'post') && Input::Post('password', false) !== '') {
            $data['password'] = Input::Post('password', false);
        }
        if (request()->has('status', 'post')) {
            $data['status'] = Input::PostInt('status');
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

        $model = new AdminCertIkuaiModel();
        $item = $model->findOrEmpty($id);

        if ($item->isEmpty()) {
            Ret::Fail(404, null, '记录不存在');
        }

        $item->delete();
        Ret::Success(0, [], '删除成功');
    }

    /**
     * 测试登录，成功后把 sess_key 保存到数据库
     */
    public function testLogin()
    {
        $id = Input::PostInt('id');
        $model = new AdminCertIkuaiModel();
        $item = $model->findOrEmpty($id);

        if ($item->isEmpty()) {
            Ret::Fail(404, null, '记录不存在');
        }

        try {
            $sess = IkuaiAction::login($item->toArray());
        } catch (Exception $e) {
            Ret::Fail(500, null, '登录失败: ' . $e->getMessage());
        }

        Ret::Success(0, [
            'sess_key' => $this->maskSessKey($sess['sess_key']),
            'last_login_time' => $sess['last_login_time'],
            'expire_time' => $sess['expire_time'] ?: '-',
            'sess_timeout' => $sess['sess_timeout'] ?: '-',
        ], '登录成功，鉴权信息已保存');
    }

    /**
     * 手动下发证书到该路由器
     */
    public function deploy()
    {
        $id = Input::PostInt('id');
        $model = new AdminCertIkuaiModel();
        $item = $model->findOrEmpty($id);

        if ($item->isEmpty()) {
            Ret::Fail(404, null, '记录不存在');
        }

        $certName = trim((string)$item['cert_name']);
        if ($certName === '') {
            Ret::Fail(400, null, '请先为该路由器绑定证书名称');
        }

        try {
            IkuaiAction::updatessl($certName, $item->toArray());
        } catch (Exception $e) {
            Ret::Fail(500, null, '下发失败: ' . $e->getMessage());
        }

        Ret::Success(0, ['cert_name' => $certName], '证书下发成功');
    }

    public function toggleStatus()
    {
        $id = Input::PostInt('id');
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminCertIkuaiModel();
        $item = $model->findOrEmpty($id);

        if ($item->isEmpty()) {
            Ret::Fail(404, null, '记录不存在');
        }

        $newStatus = $item['status'] == 1 ? 0 : 1;
        $item->save(['status' => $newStatus]);

        $statusText = $newStatus == 1 ? '启用' : '禁用';
        Ret::Success(0, ['status' => $newStatus], "状态已切换为{$statusText}");
    }

    public function batchDelete()
    {
        $ids = request()->post('ids');
        if (empty($ids)) {
            Ret::Fail(400, null, '缺少参数[ids]');
        }

        $ids = json_decode($ids, true);
        if (!is_array($ids) || empty($ids)) {
            Ret::Fail(400, null, '参数格式错误');
        }

        $count = 0;
        $model = new AdminCertIkuaiModel();
        foreach ($ids as $id) {
            $id = intval($id);
            if ($id) {
                $item = $model->findOrEmpty($id);
                if (!$item->isEmpty()) {
                    $item->delete();
                    $count++;
                }
            }
        }

        Ret::Success(0, ['count' => $count], "已删除 {$count} 条记录");
    }

    /**
     * 脱敏展示 sess_key
     */
    private function maskSessKey(string $sessKey): string
    {
        $length = strlen($sessKey);
        if ($length === 0) {
            return '';
        }
        if ($length <= 10) {
            return substr($sessKey, 0, 2) . '****';
        }

        return substr($sessKey, 0, 6) . '****' . substr($sessKey, -4);
    }
}
