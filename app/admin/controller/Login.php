<?php

namespace app\admin\controller;

use app\admin\model\AdminUserModel;
use app\admin\utils\AdminAuth;
use BaseController\CommonController;
use Input;
use Ret;

class Login extends CommonController
{
    public function index()
    {
        $username = Input::Post('username');
        $password = Input::Post('password');
        $code = Input::Post('code');

        // 验证验证码（从 cookie 读取 hash）
        $captchaHash = $_COOKIE['admin_captcha'] ?? null;
        if (empty($captchaHash)) {
            Ret::Fail(400, null, '请先获取验证码');
        }
        $code = mb_strtolower($code, 'UTF-8');
        if (!password_verify($code, $captchaHash)) {
            setcookie('admin_captcha', '', time() - 3600, '/');
            Ret::Fail(400, null, '验证码错误');
        }
        setcookie('admin_captcha', '', time() - 3600, '/');

        $model = new AdminUserModel();
        $user = $model->api_find_username($username);

        if ($user->isEmpty()) {
            Ret::Fail(401, null, '用户名或密码错误');
        }

        if ($user['status'] != 1) {
            Ret::Fail(403, null, '账号已被禁用');
        }

        if (!password_verify($password, $user['password'])) {
            Ret::Fail(401, null, '用户名或密码错误');
        }

        $token = AdminAuth::generateToken();

        $user->save([
            'token' => $token,
            'login_ip' => request()->ip(),
            'login_time' => date('Y-m-d H:i:s'),
        ]);

        setcookie('admin_token', $token, time() + 86400, '/');

        Ret::Success(0, [
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'nickname' => $user['nickname'],
                'avatar' => $user['avatar'],
                'is_super' => $user['is_super'],
            ],
        ], '登录成功');
    }

    public function logout()
    {
        $user = AdminAuth::requireLogin();
        $model = new AdminUserModel();
        $model->api_find_id($user['id'])->save(['token' => '']);
        setcookie('admin_token', '', time() - 3600, '/');
        Ret::Success(0, [], '已退出登录');
    }

    public function info()
    {
        $user = AdminAuth::requireLogin();
        Ret::Success(0, [
            'id' => $user['id'],
            'username' => $user['username'],
            'nickname' => $user['nickname'],
            'avatar' => $user['avatar'],
            'email' => $user['email'],
            'phone' => $user['phone'],
            'is_super' => $user['is_super'],
        ]);
    }
}