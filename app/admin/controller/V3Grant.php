<?php

namespace app\admin\controller;

use app\admin\model\AdminAgnesModel;
use app\admin\model\AdminV3GrantModel;
use app\admin\model\AdminV3ProjectModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use app\v3\ai\action\AiAction;
use BaseController\CommonController;
use Exception;
use Input;
use Ret;

/**
 * AI 授权：将 agnes AI key 分配给 v3 项目（ao_v3_project_agnes，多对多）
 *
 * 一个项目可被授权多个 key，标记默认者供 AI 请求实际使用；
 * 对外模型名（model_alias）与对话测试也在此页维护，实现「AI 的归 AI」。
 */
class V3Grant extends CommonController
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

        $agnesNames = [];
        foreach (AdminAgnesModel::agnesOptions() as $opt) {
            $agnesNames[(int)$opt['id']] = $opt['name'];
        }

        $items = [];
        foreach ($list['data'] as $item) {
            $projectId = (int)$item['id'];
            $grants = AdminV3GrantModel::grantsOf($projectId);

            $grantNames = [];
            $defaultId = 0;
            $defaultName = '';
            foreach ($grants as $grant) {
                $agnesId = (int)$grant['agnes_api_id'];
                $name = $agnesNames[$agnesId] ?? ('已删除(' . $agnesId . ')');
                $grantNames[] = $name;
                if ((int)$grant['is_default'] === 1) {
                    $defaultId = $agnesId;
                    $defaultName = $name;
                }
            }

            $items[] = [
                'id' => $projectId,
                'name' => $item['name'],
                'model_alias' => (string)($item['model_alias'] ?? ''),
                'status' => $item['status'],
                'grant_names' => $grantNames,
                'grant_count' => count($grantNames),
                'default_id' => $defaultId,
                'default_name' => $defaultName,
            ];
        }

        $pagination = Layout::pagination((int)$list['current_page'], max(1, (int)ceil($list['total'] / $limit)), '/admin/v3_grant', ['search' => $search], $limit);

        return $this->renderPage('v3_grant/index', [
            'list' => $items,
            'search' => $search,
            'pagination' => $pagination,
            'agnesOptions' => AdminAgnesModel::agnesOptions(),
        ], 'AI 授权', 'ai', 'v3_grant');
    }

    /**
     * 查询某项目当前授权列表（分配弹窗回显）
     * GET: project_id
     */
    public function grants()
    {
        $projectId = (int)Input::Get('project_id');
        if (!$projectId) {
            Ret::Fail(400, null, '缺少参数[project_id]');
        }
        Ret::Success(0, AdminV3GrantModel::grantsOf((int)$projectId));
    }

    /**
     * 保存某项目的授权集合 + 对外模型名
     * POST: project_id, agnes_ids(逗号分隔), default_agnes_id, model_alias
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

        $agnesIdsRaw = (string)Input::Post('agnes_ids', false);
        $agnesIds = $agnesIdsRaw === '' ? [] : explode(',', $agnesIdsRaw);
        foreach ($agnesIds as $agnesId) {
            if ((int)$agnesId > 0 && empty(AdminAgnesModel::agnesConfig((int)$agnesId))) {
                Ret::Fail(400, null, 'agnes API 不可用(ID: ' . (int)$agnesId . ')');
            }
        }

        AdminV3GrantModel::replaceGrants($projectId, $agnesIds, Input::PostInt('default_agnes_id', 0));

        if (request()->has('model_alias', 'post')) {
            $project->save(['model_alias' => trim((string)Input::Post('model_alias', false))]);
        }

        Ret::Success(0, [], '授权已保存');
    }

    /**
     * 在线测试：按项目默认授权的 agnes key 调用一次 chat/completions
     */
    public function test()
    {
        $projectId = Input::PostInt('project_id');
        if (!$projectId) {
            Ret::Fail(400, null, '缺少参数[project_id]');
        }

        $project = AdminV3ProjectModel::findOrEmpty($projectId);
        if ($project->isEmpty()) {
            Ret::Fail(404, null, '项目不存在');
        }

        $proc = AdminAgnesModel::agnesConfig((int)\think\facade\Db::name('ao_v3_project_agnes')
            ->where('project_id', $projectId)
            ->where('is_default', 1)
            ->value('agnes_api_id'));
        if (empty($proc) || (string)$proc['api_key'] === '') {
            Ret::Fail(400, null, '项目未授权可用的默认 agnes API');
        }

        $prompt = Input::Post('prompt', '用一句话介绍你自己。');
        if ($prompt === '') {
            $prompt = '用一句话介绍你自己。';
        }

        try {
            $data = AiAction::chatCompletions($proc, [
                ['role' => 'user', 'content' => $prompt],
            ], [
                'max_tokens' => 256,
            ]);
        } catch (Exception $e) {
            Ret::Fail(500, null, '测试失败: ' . $e->getMessage());
        }

        $content = $data['choices'][0]['message']['content'] ?? '';
        $usage = $data['usage'] ?? [];

        Ret::Success(0, [
            'reply' => $content,
            'usage' => $usage,
        ], '测试成功');
    }
}
