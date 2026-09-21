<?php

namespace app\index\controller;

use app\index\model\CallLogModel;
use app\index\model\ProjectModel;
use app\index\utils\ConsoleLayout;
use app\index\utils\UserAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;
use Ret;

class CallLog extends CommonController
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
        }
        Ret::Fail(405, null, '不支持的请求方法');
    }

    private function page()
    {
        $uid = intval(UserAuth::getLoginUser()['id']);
        $page = input('get.page', 1, 'intval');
        $limit = 15;
        $appid = input('get.appid', 0, 'intval');

        // 项目过滤下拉（仅自己的项目）
        $projectModel = new ProjectModel();
        $projects = $projectModel->where('uid', '=', $uid)
            ->order('appid', 'desc')
            ->column('project', 'appid');

        // 非法 appid（非本人项目）视为不过滤
        if ($appid > 0 && !isset($projects[$appid])) {
            $appid = 0;
        }

        $model = new CallLogModel();
        $list = $model->api_list_by_uid($uid, $appid, $page, $limit);

        $items = [];
        foreach ($list['data'] as $item) {
            $items[] = [
                'id' => $item['id'],
                'appid' => $item['appid'],
                'project' => $item['project'],
                'path' => $item['path'],
                'method' => $item['method'],
                'ip' => $item['ip'],
                'date' => $item['date'],
            ];
        }

        $extraParams = [];
        if ($appid > 0) {
            $extraParams['appid'] = $appid;
        }
        $pagination = Layout::pagination((int)$list['current_page'], max(1, (int)ceil($list['total'] / $limit)), '/console/call_log', $extraParams, $limit);

        return ConsoleLayout::render('call_log/index', [
            'list' => $items,
            'projects' => $projects,
            'appid' => $appid,
            'pagination' => $pagination,
        ], '调用日志', 'project', 'call_log');
    }
}
