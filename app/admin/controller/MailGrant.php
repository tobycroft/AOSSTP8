<?php

namespace app\admin\controller;

use app\admin\model\AdminMailGrantModel;
use app\admin\model\AdminMailServerModel;
use app\admin\model\AdminV3ProjectModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use app\v3\mail\action\MailAction;
use BaseController\CommonController;
use Input;
use Ret;

/**
 * 邮件授权：将邮件服务器分配给 v3 项目（ao_v3_project_mail，多对多）
 *
 * 一个项目可被授权多个服务器，标记默认者供 v3 邮件请求实际使用；
 * 被授权的项目即可调用 /v3/mail/send/index。
 */
class MailGrant extends CommonController
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
                return $this->assign();
        }
        Ret::Fail(405, null, '不支持的请求方法');
    }

    private function page()
    {
        $page = input('get.page', 1, 'intval');
        $limit = 15;
        $search = input('get.search', '');

        $model = new AdminV3ProjectModel();
        $query = $model->order('id', 'desc');
        if (!empty($search)) {
            $query->where('name', 'like', '%' . $search . '%');
        }
        $list = $query->paginate($limit, false, ['page' => $page])->toArray();

        $serverNames = [];
        foreach (AdminMailServerModel::serverOptions() as $opt) {
            $serverNames[(int)$opt['id']] = $opt['name'];
        }

        $items = [];
        foreach ($list['data'] as $item) {
            $projectId = (int)$item['id'];
            $grants = AdminMailGrantModel::grantsOf($projectId);

            $grantNames = [];
            $defaultId = 0;
            $defaultName = '';
            foreach ($grants as $grant) {
                $serverId = (int)$grant['mail_server_id'];
                $name = $serverNames[$serverId] ?? ('已删除(' . $serverId . ')');
                $grantNames[] = $name;
                if ((int)$grant['is_default'] === 1) {
                    $defaultId = $serverId;
                    $defaultName = $name;
                }
            }

            $items[] = [
                'id' => $projectId,
                'name' => $item['name'],
                'status' => $item['status'],
                'grant_names' => $grantNames,
                'grant_count' => count($grantNames),
                'default_id' => $defaultId,
                'default_name' => $defaultName,
            ];
        }

        $pagination = Layout::pagination((int)$list['current_page'], max(1, (int)ceil($list['total'] / $limit)), '/admin/mail_grant', ['search' => $search], $limit);

        return $this->renderPage('mail_grant/index', [
            'list' => $items,
            'search' => $search,
            'pagination' => $pagination,
            'serverOptions' => AdminMailServerModel::serverOptions(),
        ], '邮件授权', 'mail', 'mail_grant');
    }

    /**
     * 查询某项目当前授权列表（分配弹窗回显）
     */
    public function grants()
    {
        $projectId = (int)Input::Get('project_id');
        if (!$projectId) {
            Ret::Fail(400, null, '缺少参数[project_id]');
        }
        Ret::Success(0, AdminMailGrantModel::grantsOf($projectId));
    }

    /**
     * 保存某项目的授权集合
     * POST: project_id, server_ids(逗号分隔), default_server_id
     */
    private function assign()
    {
        $projectId = Input::PostInt('project_id');
        if (!$projectId) {
            Ret::Fail(400, null, '缺少参数[project_id]');
        }

        $project = AdminV3ProjectModel::findOrEmpty($projectId);
        if ($project->isEmpty()) {
            Ret::Fail(404, null, '项目不存在');
        }

        $serverIdsRaw = (string)Input::Post('server_ids', false);
        $serverIds = $serverIdsRaw === '' ? [] : explode(',', $serverIdsRaw);
        foreach ($serverIds as $serverId) {
            if ((int)$serverId > 0 && empty(AdminMailServerModel::serverConfig((int)$serverId))) {
                Ret::Fail(400, null, '邮件服务器不可用(ID: ' . (int)$serverId . ')');
            }
        }

        AdminMailGrantModel::replaceGrants($projectId, $serverIds, Input::PostInt('default_server_id', 0));
        Ret::Success(0, [], '授权已保存');
    }

    /**
     * 在线测试：按项目默认授权的服务器发一封验证邮件
     */
    public function test()
    {
        $projectId = Input::PostInt('project_id');
        $to = trim(Input::Post('to', false));
        if (!$projectId) {
            Ret::Fail(400, null, '缺少参数[project_id]');
        }
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            Ret::Fail(400, null, '请填写合法的测试收件人邮箱');
        }

        $project = AdminV3ProjectModel::findOrEmpty($projectId);
        if ($project->isEmpty()) {
            Ret::Fail(404, null, '项目不存在');
        }

        $defaultId = \think\facade\Db::name('ao_v3_project_mail')
            ->where('project_id', $projectId)
            ->where('is_default', 1)
            ->value('mail_server_id');
        $server = AdminMailServerModel::serverConfig((int)$defaultId);
        if (empty($server)) {
            Ret::Fail(400, null, '项目未授权可用的默认邮件服务器，请先分配');
        }

        $subject = 'AOSS 邮件授权连接测试';
        $body = '<h3>AOSS 邮件授权连接测试</h3><p>这是一封由 AOSS 后台发出的测试邮件，收到即表示该项目邮件授权配置可用。</p><p>服务器：' . htmlspecialchars((string)$server['name']) . '</p><p>时间：' . date('Y-m-d H:i:s') . '</p>';

        // 分阶段诊断：DNS / TCP / SMTP，始终 code=0 返回，发信成败体现在 data.ok
        $diag = MailAction::diagnose($server, [
            'to'      => $to,
            'subject' => $subject,
            'body'    => $body,
            'is_html' => 1,
        ]);
        Ret::Success(0, $diag, $diag['ok'] ? '测试邮件已发送' : '发送失败，请查看下方诊断');
    }
}
