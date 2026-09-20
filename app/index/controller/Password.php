<?php

namespace app\index\controller;

use app\index\model\UserModel;
use app\index\utils\ConsoleLayout;
use app\index\utils\UserAuth;
use BaseController\CommonController;
use Input;
use Ret;

class Password extends CommonController
{
    public function initialize()
    {
        parent::initialize();
        UserAuth::requireLogin();
    }

    public function index()
    {
        $method = request()->method();
        switch ($method) {
            case 'GET':
                return $this->page();
            case 'POST':
                return $this->doChange();
        }
        Ret::Fail(405, null, '不支持的请求方法');
    }

    private function page()
    {
        return ConsoleLayout::render('password/index', [], '修改密码', 'account', 'password');
    }

    private function doChange()
    {
        $user = UserAuth::getLoginUser();
        $old_password = Input::Post('old_password');
        $new_password = Input::Post('new_password');
        $confirm_password = Input::Post('confirm_password');

        if (empty($old_password) || empty($new_password)) {
            Ret::Fail(400, null, '请填写完整');
        }
        if (strlen($new_password) < 6) {
            Ret::Fail(400, null, '新密码至少6位');
        }
        if ($new_password !== $confirm_password) {
            Ret::Fail(400, null, '两次输入的新密码不一致');
        }

        $model = new UserModel();
        $item = $model->api_find_id(intval($user['id']));
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '用户不存在');
        }

        if (!password_verify($old_password, $item['password'])) {
            Ret::Fail(400, null, '旧密码错误');
        }

        $item->save([
            'password' => password_hash($new_password, PASSWORD_DEFAULT),
        ]);

        Ret::Success(0, [], '密码修改成功');
    }
}
