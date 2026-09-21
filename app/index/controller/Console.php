<?php

namespace app\index\controller;

use app\index\model\HookModel;
use app\index\model\ProjectModel;
use app\index\model\LoginLogModel;
use app\index\utils\ConsoleLayout;
use app\index\utils\UserAuth;
use BaseController\CommonController;

class Console extends CommonController
{
    public function initialize()
    {
        parent::initialize();
        UserAuth::requireLogin();
    }

    public function index()
    {
        $user = UserAuth::getLoginUser();
        $uid = intval($user['id']);

        $projectModel = new ProjectModel();
        $total = $projectModel->where('uid', '=', $uid)->count();
        $avail = $projectModel->where('uid', '=', $uid)->where('is_avail', '=', 1)->count();
        $recent = $projectModel->where('uid', '=', $uid)->order('appid', 'desc')->limit(5)->select();

        $projects = [];
        foreach ($recent as $item) {
            $projects[] = [
                'appid' => $item['appid'],
                'project' => $item['project'],
                'open_token' => $item['open_token'] ?: '-',
                'is_avail' => $item['is_avail'],
                'date' => $item['date'],
            ];
        }

        $hookModel = new HookModel();
        $hook_total = $hookModel->where('uid', '=', $uid)->count();
        $hook_avail = $hookModel->where('uid', '=', $uid)->where('status', '=', 1)->count();

        $loginLogModel = new LoginLogModel();
        $last_login = $loginLogModel->where('uid', '=', $uid)->order('id', 'desc')->findOrEmpty();

        return ConsoleLayout::render('console/index', [
            'user' => [
                'username' => $user['username'],
                'nickname' => $user['nickname'],
                'login_ip' => $user['login_ip'] ?? '-',
                'login_time' => $user['login_time'] ?? '-',
                'date' => $user['date'] ?? '-',
            ],
            'total' => $total,
            'avail' => $avail,
            'projects' => $projects,
            'hook_total' => $hook_total,
            'hook_avail' => $hook_avail,
            'last_login_ip' => $last_login->isEmpty() ? '-' : $last_login['ip'],
            'last_login_time' => $last_login->isEmpty() ? '-' : $last_login['date'],
        ], '控制台', null, 'console');
    }
}
