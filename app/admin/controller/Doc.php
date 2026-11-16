<?php

namespace app\admin\controller;

use app\admin\utils\AdminAuth;
use BaseController\CommonController;
use Ret;
use think\facade\View;

class Doc extends CommonController
{
    public function initialize()
    {
        parent::initialize();
        AdminAuth::requireLogin();
    }

    public function index()
    {
        if (!request()->isGet()) {
            Ret::Fail(405, null, '不支持的请求方法');
        }

        // 接口文档为独立页面，不套用后台框架，避免与文档目录形成双侧边栏
        View::assign('title', '接口文档');

        return response(View::fetch('doc/index'));
    }
}
