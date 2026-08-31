<?php

namespace app\admin\controller;

use app\admin\model\AdminUserModel;
use app\admin\model\AdminRoleUserModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
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

        $model = new AdminUserModel();
        $list = $model->api_list($page, $limit);

        $currentPage = (int)$list->currentPage();
        $total = $list->total();
        $totalPages = max(1, (int)ceil($total / $limit));

        $items = [];
        foreach ($list as $item) {
            $items[] = [
                'id' => $item['id'],
                'username' => $item['username'],
                'nickname' => $item['nickname'],
                'email' => $item['email'],
                'phone' => $item['phone'],
                'login_time' => $item['login_time'],
                'status' => $item['status'],
                'is_super' => $item['is_super'],
            ];
        }

        $pagination = Layout::pagination($currentPage, $totalPages, '/admin/user');

        return $this->renderPage('user/index', [
            'list' => $items,
            'pagination' => $pagination,
        ], '用户管理', 'admin', 'user');
    }

    private function create()
    {
        $username = request()->put('username');
        $password = request()->put('password');
        $nickname = request()->put('nickname', '') ?: $username;
        $email = request()->put('email', '');
        $phone = request()->put('phone', '');
        $status = intval(request()->put('status', '1')) ?: 1;

        if (empty($username)) {
            Ret::Fail(400, null, '用户名不能为空');
        }
        if (empty($password)) {
            Ret::Fail(400, null, '密码不能为空');
        }

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

    private function update()
    {
        $id = Input::PostInt('id');
        $model = new AdminUserModel();
        $user = $model->api_find_id($id);

        if ($user->isEmpty()) {
            Ret::Fail(404, null, '用户不存在');
        }

        $data = [];
        if (request()->has('username', 'post')) {
            $data['username'] = Input::Post('username');
        }
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

    private function delete()
    {
        $id = intval(request()->delete('id'));
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

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