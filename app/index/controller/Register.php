<?php

namespace app\index\controller;

use app\index\model\UserModel;
use app\index\utils\UserAuth;
use BaseController\CommonController;
use Input;
use Ret;
use think\facade\View;

class Register extends CommonController
{
    public function index()
    {
        $method = request()->method();
        switch ($method) {
            case 'GET':
                return $this->page();
            case 'POST':
                return $this->doRegister();
        }
        Ret::Fail(405, null, '不支持的请求方法');
    }

    private function page()
    {
        View::assign([
            'title' => '注册',
        ]);
        return response(View::fetch('register/index'));
    }

    private function doRegister()
    {
        $username = Input::Post('username', false, true);
        $password = Input::Post('password');
        $nickname = Input::Post('nickname', false, true);
        $code = Input::Post('code');

        if (empty($username) || strlen($username) < 2) {
            Ret::Fail(400, null, '用户名至少2个字符');
        }
        if (strlen($password) < 6) {
            Ret::Fail(400, null, '密码至少6位');
        }

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
        if (!$user->isEmpty()) {
            Ret::Fail(406, null, '用户名已存在');
        }

        $token = UserAuth::generateToken();
        $ip = request()->ip();

        $model->save([
            'username' => $username,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'nickname' => empty($nickname) ? $username : $nickname,
            'token' => $token,
            'login_ip' => $ip,
            'login_time' => date('Y-m-d H:i:s'),
        ]);

        setcookie('user_token', $token, time() + 86400, '/');

        Ret::Success(0, [
            'token' => $token,
            'user' => [
                'username' => $username,
                'nickname' => empty($nickname) ? $username : $nickname,
            ],
        ], '注册成功');
    }
}
