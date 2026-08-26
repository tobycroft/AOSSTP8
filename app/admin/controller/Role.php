<?php

namespace app\admin\controller;

use app\admin\model\AdminRoleModel;
use app\admin\model\AdminRoleUserModel;
use app\admin\utils\AdminAuth;
use BaseController\CommonController;
use Input;
use Ret;

class Role extends CommonController
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

        $model = new AdminRoleModel();
        $list = $model->api_list($page, $limit);

        $items = [];
        foreach ($list as $item) {
            $items[] = [
                'id' => $item['id'],
                'name' => $item['name'],
                'description' => $item['description'],
                'status' => $item['status'],
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
        $name = Input::Post('name');
        $description = Input::Post('description', false);

        $role = AdminRoleModel::create([
            'name' => $name,
            'description' => $description,
        ]);

        Ret::Success(0, ['id' => $role['id']], '创建成功');
    }

    public function update()
    {
        $id = Input::PostInt('id');
        $model = new AdminRoleModel();
        $role = $model->findOrEmpty($id);

        if ($role->isEmpty()) {
            Ret::Fail(404, null, '角色不存在');
        }

        $data = [];
        if (request()->has('name', 'post')) {
            $data['name'] = Input::Post('name');
        }
        if (request()->has('description', 'post')) {
            $data['description'] = Input::Post('description', false);
        }
        if (request()->has('status', 'post')) {
            $data['status'] = Input::PostInt('status');
        }

        $role->save($data);
        Ret::Success(0, [], '更新成功');
    }

    public function delete()
    {
        $id = Input::PostInt('id');
        $model = new AdminRoleModel();
        $role = $model->findOrEmpty($id);

        if ($role->isEmpty()) {
            Ret::Fail(404, null, '角色不存在');
        }

        $role->delete();
        AdminRoleUserModel::where('role_id', '=', $id)->delete();

        Ret::Success(0, [], '删除成功');
    }

    public function all()
    {
        $model = new AdminRoleModel();
        $list = $model->where('status', '=', 1)->select();

        $items = [];
        foreach ($list as $item) {
            $items[] = [
                'id' => $item['id'],
                'name' => $item['name'],
            ];
        }

        Ret::Success(0, $items);
    }

    public function bind()
    {
        $user_id = Input::PostInt('user_id');
        $role_ids = Input::PostJson('role_ids');

        (new AdminRoleUserModel())->api_delete_by_user($user_id);

        foreach ($role_ids as $role_id) {
            AdminRoleUserModel::create([
                'user_id' => $user_id,
                'role_id' => $role_id,
            ]);
        }

        Ret::Success(0, [], '绑定成功');
    }

    public function getUserRoles()
    {
        $user_id = Input::PostInt('user_id');
        $list = (new AdminRoleUserModel())->api_find_by_user($user_id);

        $role_ids = [];
        foreach ($list as $item) {
            $role_ids[] = $item['role_id'];
        }

        Ret::Success(0, $role_ids);
    }
}