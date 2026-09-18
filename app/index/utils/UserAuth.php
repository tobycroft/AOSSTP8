<?php

namespace app\index\utils;

use app\index\model\UserModel;
use Input;
use Ret;

class UserAuth
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
        $token = Input::Combi('user_token', false);
        if (empty($token)) {
            $token = request()->header('user-token');
        }
        if (empty($token)) {
            $token = $_COOKIE['user_token'] ?? '';
        }
        if (empty($token)) {
            return [];
        }

        $model = new UserModel();
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
                header('Location: /login');
                exit;
            }
            Ret::Fail(-1, null, '登录失效请重新登录');
        }
        return $user;
    }
}
