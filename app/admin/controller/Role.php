<?php

namespace app\admin\controller;

use app\admin\model\AdminRoleModel;
use app\admin\model\AdminRoleUserModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
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

        $currentPage = (int)$list->currentPage();
        $total = $list->total();
        $totalPages = max(1, (int)ceil($total / $limit));

        $html = Layout::begin('角色管理', 'admin', 'role');
        foreach ($items as $item) {
            $statusBadge = $item['status'] == 1
                ? '<span class="status-badge active">启用</span>'
                : '<span class="status-badge inactive">禁用</span>';
            $html .= <<<ROW
            <tr>
                <td>{$item['id']}</td>
                <td>{$item['name']}</td>
                <td>{$item['description']}</td>
                <td>{$statusBadge}</td>
                <td>{$item['date']}</td>
                <td>
                    <button class="btn btn-sm btn-edit" onclick="openEdit({$item['id']}, '{$item['name']}', '{$item['description']}', {$item['status']})">编辑</button>
                    <button class="btn btn-sm btn-del" onclick="doDelete({$item['id']})">删除</button>
                </td>
            </tr>
ROW;
        }
        $html .= Layout::pagination($currentPage, $totalPages, '/admin/role');
        $html .= Layout::end();
        return response($html)->contentType('text/html');
    }

    private function create()
    {
        $name = request()->put('name');
        $description = request()->put('description', '');

        if (empty($name)) {
            Ret::Fail(400, null, '名称不能为空');
        }

        $role = AdminRoleModel::create([
            'name' => $name,
            'description' => $description,
        ]);

        Ret::Success(0, ['id' => $role['id']], '创建成功');
    }

    private function update()
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

    private function delete()
    {
        $id = intval(request()->delete('id'));
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

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