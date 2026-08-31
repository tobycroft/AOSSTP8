<?php

namespace app\admin\controller;

use app\admin\model\AdminProjectModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;
use Input;
use Ret;

class Project extends CommonController
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

        $model = new AdminProjectModel();
        $query = $model->order('appid', 'desc');
        if (!empty($search)) {
            $query->where('project', 'like', '%' . $search . '%');
        }
        $list = $query->paginate($limit, false, ['page' => $page])->toArray();

        $items = [];
        foreach ($list['data'] as $item) {
            $availBadge = $item['is_avail'] == 1
                ? '<span class="status-badge active">是</span>'
                : '<span class="status-badge inactive">否</span>';
            $tokenBadge = $item['is_opentoken'] == 1
                ? '<span class="status-badge active">启用</span>'
                : '<span class="status-badge inactive">关闭</span>';
            $items[] = [
                'appid' => $item['appid'],
                'project' => $item['project'],
                'appsecret' => $item['appsecret'] ?? '',
                'appsecret_short' => mb_strlen($item['appsecret'] ?? '') > 16 ? mb_substr($item['appsecret'], 0, 16) . '...' : ($item['appsecret'] ?: '-'),
                'open_token' => $item['open_token'] ?? '',
                'open_token_short' => mb_strlen($item['open_token'] ?? '') > 16 ? mb_substr($item['open_token'], 0, 16) . '...' : ($item['open_token'] ?: '-'),
                'is_opentoken' => $item['is_opentoken'],
                'token_badge' => $tokenBadge,
                'oss_project' => $item['oss_project'],
                'is_avail' => $item['is_avail'],
                'avail_badge' => $availBadge,
                'date' => $item['date'],
            ];
        }

        $pagination = Layout::pagination((int)$list['current_page'], max(1, (int)ceil($list['total'] / $limit)), '/admin/project', ['search' => $search], $limit);

        return $this->renderPage('project/index', [
            'list' => $items,
            'search' => $search,
            'pagination' => $pagination,
        ], '项目管理', 'project', 'project');
    }

    private function create()
    {
        $project = Input::Put('project');
        if (empty($project)) {
            Ret::Fail(400, null, '项目名称不能为空');
        }

        $data = [
            'project' => $project,
            'appsecret' => Input::Put('appsecret', ''),
            'open_token' => Input::Put('open_token', ''),
            'is_opentoken' => Input::PutInt('is_opentoken', 1),
            'oss_project' => Input::Put('oss_project', ''),
            'is_avail' => Input::PutInt('is_avail', 1),
        ];

        AdminProjectModel::create($data);
        Ret::Success(0, [], '创建成功');
    }

    private function update()
    {
        $appid = Input::PostInt('appid');
        if (!$appid) {
            Ret::Fail(400, null, '缺少参数[appid]');
        }

        $model = new AdminProjectModel();
        $item = $model->findOrEmpty($appid);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '项目不存在');
        }

        $data = [];
        if (request()->has('project', 'post')) $data['project'] = Input::Post('project');
        if (request()->has('appsecret', 'post')) $data['appsecret'] = Input::Post('appsecret');
        if (request()->has('open_token', 'post')) $data['open_token'] = Input::Post('open_token');
        if (request()->has('is_opentoken', 'post')) $data['is_opentoken'] = Input::PostInt('is_opentoken');
        if (request()->has('oss_project', 'post')) $data['oss_project'] = Input::Post('oss_project');
        if (request()->has('is_avail', 'post')) $data['is_avail'] = Input::PostInt('is_avail');

        $item->save($data);
        Ret::Success(0, [], '更新成功');
    }

    private function delete()
    {
        $appid = intval(request()->delete('appid'));
        if (!$appid) {
            Ret::Fail(400, null, '缺少参数[appid]');
        }

        $model = new AdminProjectModel();
        $item = $model->findOrEmpty($appid);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '项目不存在');
        }

        $item->delete();
        Ret::Success(0, [], '删除成功');
    }
}