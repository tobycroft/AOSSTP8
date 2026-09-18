<?php

namespace app\index\controller;

use app\index\model\UserModel;
use app\index\utils\UserAuth;
use BaseController\CommonController;
use Input;
use Ret;
use think\facade\View;

class Login extends CommonController
{
    public function index()
    {
        $method = request()->method();
        switch ($method) {
            case 'GET':
                return $this->page();
            case 'POST':
                return $this->doLogin();
        }
        Ret::Fail(405, null, '不支持的请求方法');
    }

    private function page()
    {
        View::assign([
            'title' => '登录',
        ]);
        return response(View::fetch('login/index'));
    }

    private function doLogin()
    {
        $username = Input::Post('username');
        $password = Input::Post('password');
        $code = Input::Post('code');
        $ip = request()->ip();

        // 验证验证码（从 cookie 读取 hash）
        $captchaHash = $_COOKIE['user_captcha'] ?? null;
        if (empty($captchaHash)) {
            Ret::Fail(400, null, '请先获取验证码');
        }
        $code = mb_strtolower($code, 'UTF-8');
        if (!password_verify($code, $captchaHash)) {
            setcookie('user_captcha', '', time() - 3600, '/');
            Ret::Fail(400, null, '验证码错误');
        }
        setcookie('user_captcha', '', time() - 3600, '/');

        $model = new UserModel();
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

        $token = UserAuth::generateToken();

        $user->save([
            'token' => $token,
            'login_ip' => $ip,
            'login_time' => date('Y-m-d H:i:s'),
        ]);

        setcookie('user_token', $token, time() + 86400, '/');

        Ret::Success(0, [
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'nickname' => $user['nickname'],
                'avatar' => $user['avatar'],
            ],
        ], '登录成功');
    }

    public function info()
    {
        $user = UserAuth::getLoginUser();
        if (empty($user)) {
            Ret::Fail(-1, null, '登录失效请重新登录');
        }
        Ret::Success(0, [
            'id' => $user['id'],
            'username' => $user['username'],
            'nickname' => $user['nickname'],
            'avatar' => $user['avatar'],
            'email' => $user['email'],
            'phone' => $user['phone'],
        ]);
    }

    public function logout()
    {
        $user = UserAuth::getLoginUser();
        if (!empty($user)) {
            $model = new UserModel();
            $model->api_find_id($user['id'])->save(['token' => '']);
        }
        setcookie('user_token', '', time() - 3600, '/');
        Ret::Success(0, [], '已退出登录');
    }
}
