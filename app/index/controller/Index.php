<?php

namespace app\index\controller;

use app\index\utils\UserAuth;
use BaseController\CommonController;
use think\facade\View;

class Index extends CommonController
{
    public function index()
    {
        $user = UserAuth::getLoginUser();

        View::assign([
            'title' => 'AOSS 私有平台',
            'is_login' => !empty($user),
            'nickname' => $user['nickname'] ?? '',
        ]);

        return response(View::fetch('index/index'));
    }

    public function indexEn()
    {
        $user = UserAuth::getLoginUser();

        View::assign([
            'title' => 'AOSS Private Platform',
            'is_login' => !empty($user),
            'nickname' => $user['nickname'] ?? '',
        ]);

        return response(View::fetch('index/index_en'));
    }
}
