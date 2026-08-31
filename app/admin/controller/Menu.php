<?php

namespace app\admin\controller;

use app\admin\model\AdminMenuModel;
use app\admin\utils\AdminAuth;
use BaseController\CommonController;
use Input;
use Ret;

class Menu extends CommonController
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
        $model = new AdminMenuModel();
        $list = $model->order('sort', 'asc')->order('id', 'asc')->select();

        $items = [];
        foreach ($list as $item) {
            $statusBadge = $item['status'] == 1
                ? '<span class="status-badge active">启用</span>'
                : '<span class="status-badge inactive">禁用</span>';
            $items[] = [
                'id' => $item['id'],
                'parent_id' => $item['parent_id'],
                'name' => $item['name'],
                'icon' => $item['icon'],
                'path' => $item['path'],
                'sort' => $item['sort'],
                'status_badge' => $statusBadge,
                'status' => $item['status'],
            ];
        }

        return $this->renderPage('menu/index', [
            'list' => $items,
        ], '菜单管理', 'admin', 'menu');
    }

    private function create()
    {
        $parent_id = intval(request()->put('parent_id', '0')) ?: 0;
        $name = request()->put('name');
        $icon = request()->put('icon', '');
        $path = request()->put('path', '');
        $sort = intval(request()->put('sort', '0')) ?: 0;
        $status = intval(request()->put('status', '1')) ?: 1;

        if (empty($name)) {
            Ret::Fail(400, null, '名称不能为空');
        }

        $menu = AdminMenuModel::create([
            'parent_id' => $parent_id,
            'name' => $name,
            'icon' => $icon,
            'path' => $path,
            'sort' => $sort,
            'status' => $status,
        ]);

        Ret::Success(0, ['id' => $menu['id']], '创建成功');
    }

    private function update()
    {
        $id = Input::PostInt('id');
        $model = new AdminMenuModel();
        $menu = $model->findOrEmpty($id);

        if ($menu->isEmpty()) {
            Ret::Fail(404, null, '菜单不存在');
        }

        $data = [];
        if (request()->has('parent_id', 'post')) {
            $data['parent_id'] = Input::PostInt('parent_id');
        }
        if (request()->has('name', 'post')) {
            $data['name'] = Input::Post('name');
        }
        if (request()->has('icon', 'post')) {
            $data['icon'] = Input::Post('icon', false);
        }
        if (request()->has('path', 'post')) {
            $data['path'] = Input::Post('path', false);
        }
        if (request()->has('sort', 'post')) {
            $data['sort'] = Input::PostInt('sort');
        }
        if (request()->has('status', 'post')) {
            $data['status'] = Input::PostInt('status');
        }

        $menu->save($data);
        Ret::Success(0, [], '更新成功');
    }

    private function delete()
    {
        $id = intval(request()->delete('id'));
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminMenuModel();
        $menu = $model->findOrEmpty($id);

        if ($menu->isEmpty()) {
            Ret::Fail(404, null, '菜单不存在');
        }

        $model->where('parent_id', '=', $id)->delete();
        $menu->delete();

        Ret::Success(0, [], '删除成功');
    }
}