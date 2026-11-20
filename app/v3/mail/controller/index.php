<?php

namespace app\v3\mail\controller;

use app\v3\common\V3EnvelopeAuth;
use app\v3\mail\model\MailServerModel;
use app\v3\project\model\V3ProjectModel;
use BaseController\CommonController;

/**
 * v3 邮件开放接口基类：凭证信封鉴权
 *
 * 鉴权流程见 app\v3\common\V3EnvelopeAuth；
 * 项目数据来自 ao_v3_project（后台「项目管理 v3」签发），
 * 发信服务器使用权来自 ao_v3_project_mail 授权（后台「邮件管理 → 邮件授权」维护），
 * 项目须至少授权一台启用中的邮件服务器才能使用邮件应用。
 */
class index extends CommonController
{
    use V3EnvelopeAuth;

    /** 项目未配置 rate_limit 时的邮件接口兜底限流：每 60 秒 30 封 */
    private const DEFAULT_RATE_LIMIT = 30;

    public function initialize()
    {
        parent::initialize();

        $this->envelopeAuth(function (string $kid) {
            $proc = V3ProjectModel::api_find_kid($kid);
            if (empty($proc)) {
                return [];
            }
            // 项目必须被授权启用的邮件服务器才能使用邮件应用
            if (!MailServerModel::hasAnyServer((int)$proc['id'])) {
                return [];
            }
            return $proc;
        }, 'v3_mail_rl:', self::DEFAULT_RATE_LIMIT);
    }
}
