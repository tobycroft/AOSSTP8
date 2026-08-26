<?php

namespace app\admin\controller;

use app\admin\model\AdminUserModel;
use app\admin\model\AdminRoleUserModel;
use app\admin\utils\AdminAuth;
use BaseController\CommonController;
use Input;
use Ret;

class User extends CommonController
{
    public function initialize()
    {
        parent::initialize();
        AdminAuth::requireLogin();
    }

    public function list()
    {
        $page = Input::PostInt('page', false) ?: 1;
        $limit = Input::PostInt('limit', false) ?: 20;

        $model = new AdminUserModel();
        $list = $model->api_list($page, $limit);

        $items = [];
        foreach ($list as $item) {
            $items[] = [
                'id' => $item['id'],
                'username' => $item['username'],
                'nickname' => $item['nickname'],
                'avatar' => $item['avatar'],
                'email' => $item['email'],
                'phone' => $item['phone'],
                'status' => $item['status'],
                'is_super' => $item['is_super'],
                'login_ip' => $item['login_ip'],
                'login_time' => $item['login_time'],
                'date' => $item['date'],
            ];
        }

        Ret::Success(0, [
            'items' => $items,
            'total' => $list->total(),
            'page' => (int)$list->currentPage(),
            'limit' => (int)$list->listRows(),
        ]);
    }

    public function create()
    {
        $username = Input::Post('username');
        $password = Input::Post('password');
        $nickname = Input::Post('nickname', false) ?: $username;
        $email = Input::Post('email', false);
        $phone = Input::Post('phone', false);
        $status = Input::PostInt('status', false) ?: 1;

        $model = new AdminUserModel();
        $exist = $model->api_find_username($username);
        if (!$exist->isEmpty()) {
            Ret::Fail(406, null, '用户名已存在');
        }

        $user = AdminUserModel::create([
            'username' => $username,
            'password' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]),
            'nickname' => $nickname,
            'email' => $email,
            'phone' => $phone,
            'status' => $status,
        ]);

        Ret::Success(0, ['id' => $user['id']], '创建成功');
    }

    public function update()
    {
        $id = Input::PostInt('id');
        $model = new AdminUserModel();
        $user = $model->api_find_id($id);

        if ($user->isEmpty()) {
            Ret::Fail(404, null, '用户不存在');
        }

        $data = [];
        if (request()->has('nickname', 'post')) {
            $data['nickname'] = Input::Post('nickname');
        }
        if (request()->has('email', 'post')) {
            $data['email'] = Input::Post('email', false);
        }
        if (request()->has('phone', 'post')) {
            $data['phone'] = Input::Post('phone', false);
        }
        if (request()->has('status', 'post')) {
            $data['status'] = Input::PostInt('status');
        }
        if (request()->has('password', 'post')) {
            $data['password'] = password_hash(Input::Post('password'), PASSWORD_BCRYPT, ['cost' => 10]);
        }

        $user->save($data);
        Ret::Success(0, [], '更新成功');
    }

    public function delete()
    {
        $id = Input::PostInt('id');

        $current = AdminAuth::getLoginUser();
        if ($current['id'] == $id) {
            Ret::Fail(403, null, '不能删除自己');
        }

        $model = new AdminUserModel();
        $user = $model->api_find_id($id);

        if ($user->isEmpty()) {
            Ret::Fail(404, null, '用户不存在');
        }

        $user->delete();
        (new AdminRoleUserModel())->api_delete_by_user($id);

        Ret::Success(0, [], '删除成功');
    }
}