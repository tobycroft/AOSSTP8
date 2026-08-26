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
            if (empty($token)) {
                return [];
            }
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
            Ret::Fail(-1, null, '登录失效请重新登录');
        }
        return $user;
    }
}