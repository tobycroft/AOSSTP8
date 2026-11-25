<?php

namespace app\admin\utils;

use app\admin\model\AdminUserModel;
use Input;
use Ret;

class AdminAuth
{
    /**
     * 生成登录令牌
     */
    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * 获取当前登录用户（从请求头中的 token）
     */
    public static function getLoginUser(): array
    {
        $token = Input::Combi('admin_token', false);
        if (empty($token)) {
            $token = request()->header('admin-token');
        }
        if (empty($token)) {
            $token = $_COOKIE['admin_token'] ?? '';
        }
        if (empty($token)) {
            return [];
        }

        $model = new AdminUserModel();
        $user = $model->where('token', '=', $token)
            ->where('status', '=', 1)
            ->findOrEmpty();

        if ($user->isEmpty()) {
            return [];
        }

        return $user->toArray();
    }

    /**
     * 获取当前登录用户，未登录则直接返回失败
     */
    public static function requireLogin(): array
    {
        $user = self::getLoginUser();
        if (empty($user)) {
            // 页面加载（GET）直接跳转登录页，AJAX（POST/PUT/DELETE）返回 JSON
            if (request()->isGet()) {
                header('Location: /admin/login');
                exit;
            }
            Ret::Fail(-1, null, '登录失效请重新登录');
        }
        return $user;
    }

    /**
     * 要求超级管理员身份，非超管直接 403
     *
     * 用于管理员账号与角色的增删改：这类操作本身就是提权入口，
     * 不能让"任意已登录用户"都能改别人的密码或给谁分配角色。
     */
    public static function requireSuper(): array
    {
        $user = self::requireLogin();
        if ((int)($user['is_super'] ?? 0) !== 1) {
            Ret::Fail(403, null, '该操作仅限超级管理员');
        }
        return $user;
    }

    /**
     * 统计当前仍处于启用状态的超管数量
     */
    public static function countActiveSuper(): int
    {
        $model = new AdminUserModel();
        return (int)$model->where('is_super', '=', 1)
            ->where('status', '=', 1)
            ->count();
    }
}