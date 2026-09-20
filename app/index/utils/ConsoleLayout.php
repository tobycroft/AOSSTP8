<?php

namespace app\index\utils;

use think\facade\View;
use think\Response;

class ConsoleLayout
{
    public static function getMenuGroups(): array
    {
        return self::$menuGroups;
    }

    private static $menuGroups = [
        'project' => [
            'title' => '项目',
            'items' => [
                'project' => ['label' => '项目管理', 'url' => '/console/project'],
            ],
        ],
    ];

    /**
     * 渲染控制台页面（模板基于门户 view 目录）
     * @param string $template 模板路径（相对于 app/index/view）
     * @param array $data 页面数据
     * @param string $title 页面标题
     * @param string|null $activeGroup 当前展开的菜单分组
     * @param string|null $activeItem 当前高亮的菜单项
     */
    public static function render(string $template, array $data = [], string $title = '', ?string $activeGroup = null, ?string $activeItem = null): Response
    {
        $user = UserAuth::getLoginUser();

        View::assign([
            'title' => $title,
            'nickname' => $user['nickname'] ?? '',
            'menuGroups' => self::getMenuGroups(),
            'activeGroup' => $activeGroup,
            'activeItem' => $activeItem,
        ]);

        View::assign($data);

        return response(View::fetch($template));
    }
}
