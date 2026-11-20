<?php

namespace app\admin\controller;

use app\admin\model\AdminMailServerModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use app\v3\mail\action\MailAction;
use BaseController\CommonController;
use Input;
use Ret;

/**
 * 邮件服务器管理：单独维护 SMTP 服务器配置，供 v3 项目授权复用。
 */
class MailServer extends CommonController
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
        $search = input('get.search', '');

        $model = new AdminMailServerModel();
        $query = $model->order('id', 'desc');
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%');
                $q->whereOr('host', 'like', '%' . $search . '%');
            });
        }
        $list = $query->paginate($limit, false, ['page' => $page])->toArray();

        $items = [];
        foreach ($list['data'] as $item) {
            $password = (string)($item['password'] ?? '');
            $items[] = [
                'id'          => $item['id'],
                'name'        => $item['name'],
                'host'        => $item['host'],
                'port'        => $item['port'],
                'encryption'  => $item['encryption'],
                'username'    => $item['username'],
                'password_set'=> $password !== '' ? 1 : 0,
                'from_addr'   => $item['from_addr'],
                'from_name'   => $item['from_name'],
                'status'      => $item['status'],
                'bound_count' => AdminMailServerModel::boundCount((int)$item['id']),
            ];
        }

        $pagination = Layout::pagination((int)$list['current_page'], max(1, (int)ceil($list['total'] / $limit)), '/admin/mail_server', ['search' => $search], $limit);

        return $this->renderPage('mail_server/index', [
            'list' => $items,
            'search' => $search,
            'pagination' => $pagination,
        ], '邮件服务器', 'mail', 'mail_server');
    }

    private function create()
    {
        $name = Input::Put('name');
        if (empty($name)) {
            Ret::Fail(400, null, '服务器名称不能为空');
        }
        $host = Input::Put('host');
        if (empty($host)) {
            Ret::Fail(400, null, 'SMTP 主机不能为空');
        }
        $port = Input::PutInt('port', 0);
        if ($port <= 0 || $port > 65535) {
            Ret::Fail(400, null, 'SMTP 端口不合法');
        }
        $encryption = strtolower(Input::Put('encryption', false));
        if (!in_array($encryption, ['ssl', 'tls', 'none'], true)) {
            Ret::Fail(400, null, '加密方式不合法');
        }

        AdminMailServerModel::create([
            'name'       => $name,
            'host'       => $host,
            'port'       => $port,
            'encryption' => $encryption,
            'username'   => Input::Put('username', false),
            'password'   => Input::Put('password', false),
            'from_addr'  => Input::Put('from_addr', false),
            'from_name'  => Input::Put('from_name', false),
            'status'     => Input::PutInt('status', 1),
        ]);
        Ret::Success(0, [], '创建成功');
    }

    private function update()
    {
        $id = Input::PostInt('id');
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminMailServerModel();
        $item = $model->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '邮件服务器不存在');
        }

        $data = [];
        if (request()->has('name', 'post')) $data['name'] = Input::Post('name');
        if (request()->has('host', 'post')) $data['host'] = Input::Post('host');
        if (request()->has('port', 'post')) {
            $port = Input::PostInt('port');
            if ($port <= 0 || $port > 65535) {
                Ret::Fail(400, null, 'SMTP 端口不合法');
            }
            $data['port'] = $port;
        }
        if (request()->has('encryption', 'post')) {
            $enc = strtolower(Input::Post('encryption'));
            if (!in_array($enc, ['ssl', 'tls', 'none'], true)) {
                Ret::Fail(400, null, '加密方式不合法');
            }
            $data['encryption'] = $enc;
        }
        if (request()->has('username', 'post')) $data['username'] = Input::Post('username', false);
        // 密码留空表示不修改，避免掩码值回写覆盖真实密码
        if (request()->has('password', 'post') && Input::Post('password', false) !== '') {
            $data['password'] = Input::Post('password', false);
        }
        if (request()->has('from_addr', 'post')) $data['from_addr'] = Input::Post('from_addr', false);
        if (request()->has('from_name', 'post')) $data['from_name'] = Input::Post('from_name', false);
        if (request()->has('status', 'post')) $data['status'] = Input::PostInt('status');

        $item->save($data);
        Ret::Success(0, [], '更新成功');
    }

    /**
     * 在线测试：用指定服务器向测试收件人发一封验证邮件
     */
    public function test()
    {
        $id = Input::PostInt('id');
        $to = trim(Input::Post('to', false));
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            Ret::Fail(400, null, '请填写合法的测试收件人邮箱');
        }

        $server = AdminMailServerModel::serverConfig($id);
        if (empty($server)) {
            Ret::Fail(400, null, '邮件服务器不存在或已禁用');
        }

        $subject = 'AOSS 邮件服务器连接测试';
        $body = '<h3>AOSS 邮件服务器连接测试</h3><p>这是一封由 AOSS 后台发出的测试邮件，收到即表示 SMTP 配置可用。</p><p>服务器：' . htmlspecialchars((string)$server['name']) . '</p><p>时间：' . date('Y-m-d H:i:s') . '</p>';

        // 分阶段诊断：DNS 解析 / TCP 连接 / SMTP 会话，结果放 data 供前端逐项展示。
        // 始终以 code=0 返回（发信失败体现在 data.ok=false），保证前端拿到完整诊断信息。
        $diag = MailAction::diagnose($server, [
            'to'      => $to,
            'subject' => $subject,
            'body'    => $body,
            'is_html' => 1,
        ]);
        Ret::Success(0, $diag, $diag['ok'] ? '测试邮件已发送' : '发送失败，请查看下方诊断');
    }

    private function delete()
    {
        $id = intval(request()->delete('id'));
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $bound = AdminMailServerModel::boundCount($id);
        if ($bound > 0) {
            Ret::Fail(400, null, '该服务器仍被 ' . $bound . ' 个项目授权，请先改绑后再删除');
        }

        $model = new AdminMailServerModel();
        $item = $model->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '邮件服务器不存在');
        }
        $item->delete();
        Ret::Success(0, [], '删除成功');
    }
}
