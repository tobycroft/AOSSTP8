<?php

namespace app\index\controller;

use app\index\model\LoginLogModel;
use app\index\utils\ConsoleLayout;
use app\index\utils\UserAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;
use Ret;

class LoginLog extends CommonController
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
        $user = UserAuth::getLoginUser();
        $uid = intval($user['id']);
        $page = input('get.page', 1, 'intval');
        $limit = 15;

        $model = new LoginLogModel();
        $list = $model->api_list_by_uid($uid, $page, $limit);

        $items = [];
        foreach ($list['data'] as $item) {
            $items[] = [
                'id' => $item['id'],
                'ip' => $item['ip'],
                'date' => $item['date'],
            ];
        }

        $pagination = Layout::pagination((int)$list['current_page'], max(1, (int)ceil($list['total'] / $limit)), '/console/login_log', [], $limit);

        return ConsoleLayout::render('login_log/index', [
            'list' => $items,
            'pagination' => $pagination,
        ], '登录记录', 'account', 'login_log');
    }
}
