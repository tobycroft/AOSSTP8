<?php

namespace app\v3\ai\controller;

use app\v3\common\V3EnvelopeAuth;
use app\v3\project\model\V3ProjectModel;
use BaseController\CommonController;
use Ret;

/**
 * v3 AI 开放接口基类：凭证信封鉴权
 *
 * 鉴权流程见 app\v3\common\V3EnvelopeAuth；
 * 项目数据来自 ao_v3_project（后台「项目管理 v3」维护）。
 * 项目未绑定可用 agnes API 时对 AI 应用不可用，保持迁移前 401 行为。
 */
class index extends CommonController
{
    use V3EnvelopeAuth;

    public function initialize()
    {
        set_time_limit(0);
        parent::initialize();

        $this->envelopeAuth(function (string $kid) {
            $proc = V3ProjectModel::api_find_kid($kid);
            // 未绑定可用 agnes API（无 api_key）时视为项目不可用，与迁移前一致
            if (empty($proc['api_key'])) {
                return [];
            }
            return $proc;
        });
    }
}
