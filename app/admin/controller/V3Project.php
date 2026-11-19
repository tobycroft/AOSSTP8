<?php

namespace app\admin\controller;

use app\admin\model\AdminAgnesModel;
use app\admin\model\AdminV3ProjectModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use app\v3\ai\action\AiAction;
use Aoss\Envelope;
use BaseController\CommonController;
use Exception;
use Input;
use Ret;

/**
 * 项目管理 v3：ao_v3_project 后台维护
 *
 * v3 项目为应用无关的通用凭证（secret + 信封鉴权），
 * 一个项目可使用 v3 下所有应用（AI、Excel 等）。
 * AI 专属配置（agnes 绑定、模型别名）直接在项目上维护，
 * 原「AI 项目」（ao_ai）已全量迁入本表。
 */
class V3Project extends CommonController
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

        $model = new AdminV3ProjectModel();
        $query = $model->order('id', 'desc');
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                // 名称模糊匹配；若输入恰为 64 位十六进制，则视为按 secret 精确定位
                $q->where('name', 'like', '%' . $search . '%');
                if (preg_match('/^[0-9a-fA-F]{64}$/', $search) === 1) {
                    $q->whereOr('secret', '=', strtolower($search));
                }
            });
        }
        $list = $query->paginate($limit, false, ['page' => $page])->toArray();

        // 一次性取出全部 agnes API 名称，供项目列表展示绑定关系（避免逐行查询）
        $agnesNames = [];
        foreach (AdminAgnesModel::agnesOptions() as $opt) {
            $agnesNames[(int)$opt['id']] = $opt['name'];
        }

        $items = [];
        foreach ($list['data'] as $item) {
            $agnesId = (int)($item['agnes_api_id'] ?? 0);
            $items[] = [
                'id' => $item['id'],
                'name' => $item['name'],
                'secret' => $item['secret'],
                'secret_mask' => $this->maskSecret((string)$item['secret']),
                'secret_strong' => Envelope::isStrongSecret((string)$item['secret']),
                'kid' => Envelope::kid((string)$item['secret']),
                'agnes_api_id' => $agnesId,
                'agnes_name' => $agnesNames[$agnesId] ?? ($agnesId > 0 ? ('未绑定(' . $agnesId . ')') : '未绑定'),
                'model_alias' => (string)($item['model_alias'] ?? ''),
                'rate_limit' => (int)($item['rate_limit'] ?? 0),
                'status' => $item['status'],
            ];
        }

        $pagination = Layout::pagination((int)$list['current_page'], max(1, (int)ceil($list['total'] / $limit)), '/admin/v3_project', ['search' => $search], $limit);

        return $this->renderPage('v3_project/index', [
            'list' => $items,
            'search' => $search,
            'pagination' => $pagination,
            'agnesOptions' => AdminAgnesModel::agnesOptions(),
        ], '项目管理 v3', 'v3', 'v3_project');
    }

    /**
     * 凭证掩码，仅用于列表展示，避免整串 secret 平铺在页面上
     */
    private function maskSecret(string $secret): string
    {
        if ($secret === '') {
            return '-';
        }
        if (mb_strlen($secret) <= 12) {
            return $secret . '（熵不足）';
        }
        return mb_substr($secret, 0, 6) . '····' . mb_substr($secret, -4);
    }

    private function create()
    {
        $name = Input::Put('name');
        if (empty($name)) {
            Ret::Fail(400, null, '项目名称不能为空');
        }

        // 凭证由服务端签发，不接受人工输入：secret 的熵直接决定信封机密性上限
        $secret = Envelope::randomSecret();
        $data = [
            'name' => $name,
            'secret' => $secret,
            'agnes_api_id' => Input::PutInt('agnes_api_id', 0),
            'model_alias' => trim((string)Input::Put('model_alias', false)),
            'rate_limit' => Input::PutInt('rate_limit', 0),
            'status' => Input::PutInt('status', 1),
        ];
        if ($data['agnes_api_id'] > 0 && empty(AdminAgnesModel::agnesConfig($data['agnes_api_id']))) {
            Ret::Fail(400, null, '请选择可用的 agnes API');
        }

        AdminV3ProjectModel::create($data);
        Ret::Success(0, [
            'secret' => $secret,
            'kid' => Envelope::kid($secret),
        ], '创建成功，secret 仅此处展示一次，请立即交付调用方');
    }

    /**
     * 重置凭证
     * 旧 secret 与旧 kid 立即失效，调用方需同步更换，否则 v3 接口一律 401。
     */
    public function resetSecret()
    {
        $id = Input::PostInt('id');
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $item = AdminV3ProjectModel::findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '项目不存在');
        }

        $secret = Envelope::randomSecret();
        $item->save(['secret' => $secret]);

        Ret::Success(0, [
            'secret' => $secret,
            'kid' => Envelope::kid($secret),
        ], 'Secret 已重置，旧凭证与旧 kid 立即失效');
    }

    private function update()
    {
        $id = Input::PostInt('id');
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminV3ProjectModel();
        $item = $model->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '项目不存在');
        }

        $data = [];
        if (request()->has('name', 'post')) $data['name'] = Input::Post('name');
        if (request()->has('agnes_api_id', 'post')) {
            $agnesApiId = Input::PostInt('agnes_api_id');
            if ($agnesApiId > 0 && empty(AdminAgnesModel::agnesConfig($agnesApiId))) {
                Ret::Fail(400, null, '请选择可用的 agnes API');
            }
            $data['agnes_api_id'] = $agnesApiId;
        }
        if (request()->has('model_alias', 'post')) {
            $data['model_alias'] = trim((string)Input::Post('model_alias', false));
        }
        if (request()->has('rate_limit', 'post')) {
            $data['rate_limit'] = Input::PostInt('rate_limit', 0);
        }
        if (request()->has('status', 'post')) $data['status'] = Input::PostInt('status');

        $item->save($data);
        Ret::Success(0, ['kid' => Envelope::kid((string)$item['secret'])], '更新成功');
    }

    private function delete()
    {
        $id = intval(request()->delete('id'));
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminV3ProjectModel();
        $item = $model->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '项目不存在');
        }

        $item->delete();
        Ret::Success(0, [], '删除成功');
    }

    /**
     * 在线测试：调用一次 chat/completions 验证 agnes 绑定可用性
     */
    public function test()
    {
        $id = Input::PostInt('id');
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminV3ProjectModel();
        $item = $model->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '项目不存在');
        }

        $proc = AdminAgnesModel::agnesConfig((int)($item['agnes_api_id'] ?? 0));
        if (empty($proc) || (string)$proc['api_key'] === '') {
            Ret::Fail(400, null, '未绑定可用的 agnes API 或 API 密钥为空');
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
