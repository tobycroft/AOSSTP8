<?php

namespace app\admin\utils;

class Layout
{
    private static $menuGroups = [
        'admin' => [
            'title' => '管理员',
            'items' => [
                'user' => ['label' => '用户管理', 'url' => '/admin/user'],
                'role' => ['label' => '角色管理', 'url' => '/admin/role'],
                'menu' => ['label' => '菜单管理', 'url' => '/admin/menu'],
            ],
        ],
        'cert' => [
            'title' => '证书管理',
            'items' => [
                'cert' => ['label' => '证书项目', 'url' => '/admin/cert'],
                'cert_url' => ['label' => '证书URL', 'url' => '/admin/cert_url'],
                'cert_website' => ['label' => '证书站点', 'url' => '/admin/cert_website'],
                'cert_log' => ['label' => '操作日志', 'url' => '/admin/cert_log'],
            ],
        ],
        'storage' => [
            'title' => '存储管理',
            'items' => [
                'attachment' => ['label' => '附件管理', 'url' => '/admin/attachment'],
            ],
        ],
    ];

    private static function css(): string
    {
        return <<<CSS
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f0f2f5; padding-top: 64px; }
.header { position: fixed; top: 0; left: 0; right: 0; z-index: 100; background: #001529; padding: 0 24px; height: 64px; display: flex; align-items: center; justify-content: space-between; color: #fff; }
.header h1 { font-size: 20px; font-weight: 500; }
.header .user-info { display: flex; align-items: center; gap: 16px; }
.header .user-info span { font-size: 14px; }
.header .btn-logout { padding: 6px 16px; background: #ff4d4f; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; }
.header .btn-logout:hover { background: #ff7875; }
.sidebar { width: 220px; background: #fff; min-height: calc(100vh - 64px); border-right: 1px solid #e8e8e8; padding: 16px 0; position: fixed; top: 64px; }
.sidebar .menu-item { padding: 12px 24px; cursor: pointer; color: #333; font-size: 14px; display: block; text-decoration: none; }
.sidebar .menu-item:hover { background: #f0f5ff; color: #1677ff; }
.sidebar .menu-item.active { background: #e6f4ff; color: #1677ff; border-right: 3px solid #1677ff; }
.sidebar .menu-group { }
.sidebar .menu-group-title { padding: 12px 24px; cursor: pointer; color: #333; font-size: 14px; display: flex; align-items: center; justify-content: space-between; user-select: none; }
.sidebar .menu-group-title:hover { background: #f0f5ff; color: #1677ff; }
.sidebar .menu-group-title .arrow { transition: transform 0.2s; font-size: 10px; }
.sidebar .menu-group-title.open .arrow { transform: rotate(90deg); }
.sidebar .menu-group-items { display: none; }
.sidebar .menu-group-items.open { display: block; }
.sidebar .menu-group-items .menu-item { padding-left: 44px; }
.main { margin-left: 220px; padding: 24px; }
.toolbar { margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; }
.btn { padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; }
.btn-primary { background: #1677ff; color: #fff; }
.btn-primary:hover { background: #4096ff; }
.btn-danger { background: #ff4d4f; color: #fff; }
.btn-danger:hover { background: #ff7875; }
.btn-sm { padding: 4px 10px; font-size: 12px; }
.btn-edit { background: #1677ff; color: #fff; margin-right: 4px; }
.btn-edit:hover { background: #4096ff; }
.btn-del { background: #ff4d4f; color: #fff; }
.btn-del:hover { background: #ff7875; }
table { width: 100%; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,0.05); border-collapse: collapse; }
th, td { padding: 12px 16px; text-align: left; border-bottom: 1px solid #f0f0f0; font-size: 14px; }
th { background: #fafafa; color: #666; font-weight: 500; }
td { color: #333; }
tr:hover { background: #fafafa; }
.pagination { margin-top: 16px; display: flex; justify-content: center; gap: 8px; }
.pagination a { padding: 6px 12px; border: 1px solid #d9d9d9; border-radius: 4px; color: #333; text-decoration: none; font-size: 14px; }
.pagination a:hover { border-color: #1677ff; color: #1677ff; }
.pagination a.active { background: #1677ff; color: #fff; border-color: #1677ff; }
.modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.4); z-index: 1000; }
.modal.show { display: flex; align-items: center; justify-content: center; }
.modal-box { background: #fff; border-radius: 8px; padding: 24px; width: 480px; max-width: 90%; }
.modal-box h3 { margin-bottom: 20px; color: #333; }
.form-group { margin-bottom: 14px; }
.form-group label { display: block; margin-bottom: 4px; color: #555; font-size: 13px; }
.form-group input, .form-group select { width: 100%; padding: 8px 10px; border: 1px solid #d9d9d9; border-radius: 4px; font-size: 14px; outline: none; }
.form-group input:focus, .form-group select:focus { border-color: #4096ff; }
.modal-actions { text-align: right; margin-top: 20px; }
.modal-actions .btn { margin-left: 8px; }
.btn-cancel { background: #fff; color: #333; border: 1px solid #d9d9d9; }
.btn-cancel:hover { border-color: #1677ff; color: #1677ff; }
.status-badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
.status-badge.active { background: #f6ffed; color: #52c41a; border: 1px solid #b7eb8f; }
.status-badge.inactive { background: #fff2f0; color: #ff4d4f; border: 1px solid #ffccc7; }
.status-badge.success { background: #f6ffed; color: #52c41a; border: 1px solid #b7eb8f; }
.status-badge.fail { background: #fff2f0; color: #ff4d4f; border: 1px solid #ffccc7; }
.card { background: #fff; border-radius: 8px; padding: 24px; margin-bottom: 16px; box-shadow: 0 1px 4px rgba(0,0,0,0.05); }
.card h3 { margin-bottom: 16px; color: #333; font-size: 16px; }
.card p { color: #666; font-size: 14px; line-height: 1.8; }
.card .info-row { display: flex; margin-bottom: 8px; }
.card .info-row .label { width: 100px; color: #999; font-size: 14px; }
.card .info-row .value { color: #333; font-size: 14px; }
.welcome { font-size: 24px; font-weight: 500; color: #333; margin-bottom: 24px; }
CSS;
    }

    /**
     * 返回页面头部：DOCTYPE + head + header + sidebar + <div class="main">
     * @param string $title 页面标题
     * @param string|null $activeGroup 当前展开的菜单分组 (admin/cert)
     * @param string|null $activeItem 当前高亮的菜单项
     */
    public static function begin(string $title, ?string $activeGroup = null, ?string $activeItem = null): string
    {
        $user = AdminAuth::getLoginUser();
        $nickname = $user['nickname'] ?? '';

        $html = '<!DOCTYPE html><html lang="zh-CN"><head>';
        $html .= '<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
        $html .= '<title>' . $title . '</title><style>' . self::css() . '</style></head><body>';

        // Header
        $html .= '<div class="header"><h1>AOSS 后台管理</h1>';
        $html .= '<div class="user-info"><span>欢迎，' . $nickname . '</span>';
        $html .= '<button class="btn-logout" onclick="doLogout()">登出</button>';
        $html .= '</div></div>';

        // Sidebar
        $html .= '<div class="sidebar">';
        $html .= '<a class="menu-item' . ($activeItem === 'console' ? ' active' : '') . '" href="/admin/console">控制台</a>';

        foreach (self::$menuGroups as $groupKey => $group) {
            $isActive = ($groupKey === $activeGroup);
            $html .= '<div class="menu-group">';
            $html .= '<div class="menu-group-title' . ($isActive ? ' open' : '') . '" onclick="toggleGroup(this)">';
            $html .= '<span>' . $group['title'] . '</span><span class="arrow">&gt;</span>';
            $html .= '</div>';
            $html .= '<div class="menu-group-items' . ($isActive ? ' open' : '') . '">';

            foreach ($group['items'] as $key => $item) {
                $isItemActive = ($key === $activeItem);
                $html .= '<a class="menu-item' . ($isItemActive ? ' active' : '') . '" href="' . $item['url'] . '">' . $item['label'] . '</a>';
            }

            $html .= '</div></div>';
        }

        $html .= '</div><div class="main">';
        return $html;
    }

    /**
     * 返回页面尾部：关闭 main 标签 + 通用 JS + </body></html>
     */
    public static function end(): string
    {
        return '</div><script>function toggleGroup(el){el.classList.toggle("open");el.nextElementSibling.classList.toggle("open");}function doLogout(){if(!confirm(\'确定要退出登录吗？\')) return;var xhr=new XMLHttpRequest();xhr.open(\'POST\',\'/admin/login/logout\',true);xhr.setRequestHeader(\'Content-Type\',\'application/x-www-form-urlencoded\');xhr.setRequestHeader(\'admin-token\',localStorage.getItem(\'admin_token\'));xhr.onreadystatechange=function(){if(xhr.readyState==4){localStorage.removeItem(\'admin_token\');document.cookie=\'admin_token=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/\';window.location.href=\'/admin/login\';}};xhr.send();}</script></body></html>';
    }

    /**
     * 生成分页 HTML
     */
    public static function pagination(int $currentPage, int $totalPages, string $baseUrl): string
    {
        if ($totalPages <= 1) {
            return '';
        }
        $html = '<div class="pagination">';
        for ($i = 1; $i <= $totalPages; $i++) {
            $active = $i == $currentPage ? ' class="active"' : '';
            $html .= '<a href="' . $baseUrl . '?page=' . $i . '"' . $active . '>' . $i . '</a>';
        }
        $html .= '</div>';
        return $html;
    }
}