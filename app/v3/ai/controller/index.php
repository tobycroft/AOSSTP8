<?php

namespace app\v3\ai\controller;

use app\v3\common\V3EnvelopeAuth;
use app\v3\project\model\V3ProjectModel;
use BaseController\CommonController;

/**
 * v3 AI 开放接口基类：凭证信封鉴权
 *
 * 鉴权流程见 app\v3\common\V3EnvelopeAuth；
 * 项目数据来自 ao_v3_project（后台「项目管理 v3」维护），
 * AI key 使用权来自 ao_v3_project_agnes 授权（后台「AI 管理 → AI 授权」维护），
 * AI 请求取项目标记为默认的 agnes key。
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
            if (empty($proc)) {
                return [];
            }
            // 项目必须被授权默认 agnes key 才能使用 AI 应用
            $agnes = V3ProjectModel::defaultAgnes((int)$proc['id']);
            if (empty($agnes)) {
                return [];
            }
            $proc['api_key'] = $agnes['api_key'];
            $proc['base_url'] = $agnes['base_url'];
            $proc['model'] = $agnes['model'];
            return $proc;
        });
    }
}
