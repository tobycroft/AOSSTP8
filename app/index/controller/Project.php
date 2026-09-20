<?php

namespace app\index\controller;

use app\index\model\ProjectModel;
use app\index\utils\ConsoleLayout;
use app\index\utils\UserAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;
use Input;
use Ret;

class Project extends CommonController
{
    public function initialize()
    {
        parent::initialize();
        UserAuth::requireLogin();
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
        $user = UserAuth::getLoginUser();
        $uid = intval($user['id']);
        $page = input('get.page', 1, 'intval');
        $limit = 15;
        $search = input('get.search', '');

        $model = new ProjectModel();
        $list = $model->api_list_by_uid($uid, $search, $page, $limit);

        $items = [];
        foreach ($list['data'] as $item) {
            $items[] = [
                'appid' => $item['appid'],
                'project' => $item['project'],
                'ak_short' => mb_strlen($item['open_token'] ?? '') > 16 ? mb_substr($item['open_token'], 0, 16) . '...' : ($item['open_token'] ?: '-'),
                'ak' => $item['open_token'] ?? '',
                'sk' => $item['appsecret'] ?? '',
                'is_avail' => $item['is_avail'],
                'date' => $item['date'],
            ];
        }

        $pagination = Layout::pagination((int)$list['current_page'], max(1, (int)ceil($list['total'] / $limit)), '/console/project', $search !== '' ? ['search' => $search] : [], $limit);

        return ConsoleLayout::render('project/index', [
            'list' => $items,
            'search' => $search,
            'pagination' => $pagination,
        ], '项目管理', 'project', 'project');
    }

    /**
     * 创建项目（自动生成 AK/SK）
     */
    private function create()
    {
        $uid = intval(UserAuth::getLoginUser()['id']);
        $project = strval(request()->put('project', ''));
        if (empty($project)) {
            Ret::Fail(400, null, '项目名称不能为空');
        }

        ProjectModel::create([
            'uid' => $uid,
            'project' => $project,
            'appsecret' => bin2hex(random_bytes(32)),
            'open_token' => bin2hex(random_bytes(32)),
            'is_opentoken' => 1,
            'oss_project' => strval(request()->put('oss_project', '')),
            'is_avail' => 1,
        ]);

        Ret::Success(0, [], '创建成功，AK/SK 已自动生成');
    }

    private function update()
    {
        $uid = intval(UserAuth::getLoginUser()['id']);
        $appid = Input::PostInt('appid');
        if (!$appid) {
            Ret::Fail(400, null, '缺少参数[appid]');
        }

        $model = new ProjectModel();
        $item = $model->api_find_uid($appid, $uid);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '项目不存在');
        }

        $data = [];
        if (request()->has('project', 'post')) $data['project'] = Input::Post('project');
        if (request()->has('oss_project', 'post')) $data['oss_project'] = Input::Post('oss_project');
        if (request()->has('is_avail', 'post')) $data['is_avail'] = Input::PostInt('is_avail');

        $item->save($data);
        Ret::Success(0, [], '更新成功');
    }

    /**
     * 重置 AK（旧 AK 立即失效）
     */
    public function resetAk()
    {
        $uid = intval(UserAuth::getLoginUser()['id']);
        $appid = Input::PostInt('appid');
        if (!$appid) {
            Ret::Fail(400, null, '缺少参数[appid]');
        }

        $model = new ProjectModel();
        $item = $model->api_find_uid($appid, $uid);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '项目不存在');
        }

        $ak = bin2hex(random_bytes(32));
        $item->save(['open_token' => $ak]);

        Ret::Success(0, ['ak' => $ak], 'AK 已重置，旧 AK 立即失效');
    }

    private function delete()
    {
        $uid = intval(UserAuth::getLoginUser()['id']);
        $appid = intval(request()->delete('appid'));
        if (!$appid) {
            Ret::Fail(400, null, '缺少参数[appid]');
        }

        $model = new ProjectModel();
        $item = $model->api_find_uid($appid, $uid);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '项目不存在');
        }

        $item->delete();
        Ret::Success(0, [], '删除成功');
    }
}
