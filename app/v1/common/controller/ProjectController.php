<?php

namespace app\v1\common\controller;


use app\v1\oss\model\OssModel;
use BaseController\CommonController;
use Input;
use Ret;

/**
 * 项目令牌基类：校验 token 并加载存储配置。
 *
 * 原 app\v1\image\controller\create 承担的初始化逻辑迁移至此，
 * 图像模块（v1/v2 image）下线后，其余模块仍复用该基类。
 */
class ProjectController extends CommonController
{
    public null|string $token = null;
    public mixed $proc;

    public function initialize()
    {
        set_time_limit(0);
        parent::initialize();
        if (!$this->token) {
            $this->token = Input::Combi('token');
        }
        $this->proc = OssModel::api_find_token($this->token);
        if (!$this->proc) {
            Ret::Fail(401, null, '项目不可用');
        }
    }
}
