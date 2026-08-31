<?php

namespace BaseController;


use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use app\BaseController;
use think\facade\View;

class CommonController extends BaseController
{
    public function initialize()
    {
        parent::initialize();
        header("Access-Control-Allow-Origin: *", true);
        header("Access-Control-Max-Age: 86400", true);
        header("Access-Control-Allow-Credentials: true", true);
        header("Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS", true);
        header("Access-Control-Allow-Headers: *", true);
        // 服务启动
    }

    /**
     * 渲染后台管理页面
     * @param string $template 模板路径（相对于 view 目录）
     * @param array $data 页面数据
     * @param string $title 页面标题
     * @param string|null $activeGroup 当前展开的菜单分组
     * @param string|null $activeItem 当前高亮的菜单项
     * @return \think\Response
     */
    protected function renderPage(string $template, array $data = [], string $title = '', ?string $activeGroup = null, ?string $activeItem = null): \think\Response
    {
        $user = AdminAuth::getLoginUser();
        $nickname = $user['nickname'] ?? '';

        View::assign([
            'title' => $title,
            'nickname' => $nickname,
            'menuGroups' => Layout::getMenuGroups(),
            'activeGroup' => $activeGroup,
            'activeItem' => $activeItem,
        ]);

        View::assign($data);

        return View::fetch($template);
    }
}