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

    public function list()
    {
        $model = new AdminMenuModel();
        $list = $model->order('sort', 'asc')->order('id', 'asc')->select();

        $items = [];
        foreach ($list as $item) {
            $items[] = [
                'id' => $item['id'],
                'parent_id' => $item['parent_id'],
                'name' => $item['name'],
                'icon' => $item['icon'],
                'path' => $item['path'],
                'component' => $item['component'],
                'permission' => $item['permission'],
                'sort' => $item['sort'],
                'status' => $item['status'],
            ];
        }

        Ret::Success(0, $items);
    }

    public function create()
    {
        $parent_id = Input::PostInt('parent_id', false) ?: 0;
        $name = Input::Post('name');
        $icon = Input::Post('icon', false);
        $path = Input::Post('path', false);
        $component = Input::Post('component', false);
        $permission = Input::Post('permission', false);
        $sort = Input::PostInt('sort', false) ?: 0;

        $menu = AdminMenuModel::create([
            'parent_id' => $parent_id,
            'name' => $name,
            'icon' => $icon,
            'path' => $path,
            'component' => $component,
            'permission' => $permission,
            'sort' => $sort,
        ]);

        Ret::Success(0, ['id' => $menu['id']], '创建成功');
    }

    public function update()
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
        if (request()->has('component', 'post')) {
            $data['component'] = Input::Post('component', false);
        }
        if (request()->has('permission', 'post')) {
            $data['permission'] = Input::Post('permission', false);
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

    public function delete()
    {
        $id = Input::PostInt('id');
        $model = new AdminMenuModel();
        $menu = $model->findOrEmpty($id);

        if ($menu->isEmpty()) {
            Ret::Fail(404, null, '菜单不存在');
        }

        // 删除子菜单
        $model->where('parent_id', '=', $id)->delete();
        $menu->delete();

        Ret::Success(0, [], '删除成功');
    }
}