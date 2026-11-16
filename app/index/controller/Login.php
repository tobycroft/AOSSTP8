<?php

namespace app\index\controller;

use app\index\model\LoginLogModel;
use app\index\model\UserModel;
use app\index\utils\LoginGuard;
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
        // 该 IP 存在未过期的登录失败记录时，登录页直接要求验证码
        View::assign([
            'title' => '登录',
            'need_captcha' => LoginGuard::needCaptcha(request()->ip()),
        ]);
        return response(View::fetch('login/index'));
    }

    private function doLogin()
    {
        $username = Input::Post('username');
        $password = Input::Post('password');
        $code = Input::Post('code', false);
        $ip = request()->ip();

        // 登录失败 ≥1 次的 IP 强制校验验证码；无失败记录时不校验
        $needCaptcha = LoginGuard::needCaptcha($ip);
        if ($needCaptcha) {
            // 验证验证码（从 cookie 读取 hash）
            $captchaHash = $_COOKIE['user_captcha'] ?? null;
            if (empty($captchaHash)) {
                Ret::Fail(400, ['need_captcha' => true], '请先获取验证码');
            }
            if (!password_verify(mb_strtolower($code, 'UTF-8'), $captchaHash)) {
                setcookie('user_captcha', '', time() - 3600, '/');
                $fails = LoginGuard::markFail($ip);
                Ret::Fail(400, ['need_captcha' => $fails >= 1], '验证码错误');
            }
            setcookie('user_captcha', '', time() - 3600, '/');
        }

        $model = new UserModel();
        $user = $model->api_find_username($username);

        if ($user->isEmpty()) {
            $fails = LoginGuard::markFail($ip);
            Ret::Fail(401, ['need_captcha' => $fails >= 1], '用户名或密码错误');
        }

        if ($user['status'] != 1) {
            $fails = LoginGuard::markFail($ip);
            Ret::Fail(403, ['need_captcha' => $fails >= 1], '账号已被禁用');
        }

        if (!password_verify($password, $user['password'])) {
            $fails = LoginGuard::markFail($ip);
            Ret::Fail(401, ['need_captcha' => $fails >= 1], '用户名或密码错误');
        }

        // 登录成功：清除该 IP 的失败状态，下次登录无需验证码
        LoginGuard::clear($ip);

        $token = UserAuth::generateToken();

        $user->save([
            'token' => $token,
            'login_ip' => $ip,
            'login_time' => date('Y-m-d H:i:s'),
        ]);

        // 记录登录日志
        LoginLogModel::addLog(intval($user['id']), strval($user['username']), $ip);

        setcookie('user_token', $token, time() + 86400, '/');

        Ret::Success(0, [
            'token' => $token,
            'need_captcha' => false,
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
