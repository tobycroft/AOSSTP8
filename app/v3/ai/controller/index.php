<?php

namespace app\v3\ai\controller;

use app\v3\ai\model\AiModel;
use app\v3\common\V3EnvelopeAuth;
use BaseController\CommonController;

/**
 * v3 AI 开放接口基类：凭证信封鉴权
 *
 * 鉴权流程见 app\v3\common\V3EnvelopeAuth；
 * 项目数据来自 ao_ai（AI 专属：绑定 agnes API、模型别名等）。
 */
class index extends CommonController
{
    use V3EnvelopeAuth;

    public function initialize()
    {
        set_time_limit(0);
        parent::initialize();

        $this->envelopeAuth(fn(string $kid) => AiModel::api_find_kid($kid));
    }
}
