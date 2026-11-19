<?php

namespace app\admin\controller;

use app\admin\model\AdminV3ProjectModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use Aoss\Envelope;
use BaseController\CommonController;
use Input;
use Ret;

/**
 * 项目管理 v3：ao_v3_project 后台维护
 *
 * v3 项目为应用无关的通用凭证（secret + 信封鉴权），
 * 一个项目可使用 v3 下所有应用（excel 等），与「AI 项目」（ao_ai）相互独立。
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

        $items = [];
        foreach ($list['data'] as $item) {
            $items[] = [
                'id' => $item['id'],
                'name' => $item['name'],
                'secret' => $item['secret'],
                'secret_mask' => $this->maskSecret((string)$item['secret']),
                'secret_strong' => Envelope::isStrongSecret((string)$item['secret']),
                'kid' => Envelope::kid((string)$item['secret']),
                'rate_limit' => (int)($item['rate_limit'] ?? 0),
                'status' => $item['status'],
            ];
        }

        $pagination = Layout::pagination((int)$list['current_page'], max(1, (int)ceil($list['total'] / $limit)), '/admin/v3_project', ['search' => $search], $limit);

        return $this->renderPage('v3_project/index', [
            'list' => $items,
            'search' => $search,
            'pagination' => $pagination,
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
            'rate_limit' => Input::PutInt('rate_limit', 0),
            'status' => Input::PutInt('status', 1),
        ];

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
}
