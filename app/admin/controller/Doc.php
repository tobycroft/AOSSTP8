<?php

namespace app\admin\controller;

use app\admin\utils\AdminAuth;
use BaseController\CommonController;
use Ret;

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

        return $this->renderPage('doc/index', [], '使用文档', null, 'doc');
    }
}
