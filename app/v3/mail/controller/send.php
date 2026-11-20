<?php

namespace app\v3\mail\controller;

use app\v3\mail\action\MailAction;
use app\v3\mail\model\MailServerModel;
use Input;
use Ret;
use think\Exception;

/**
 * POST /v3/mail/send/index —— SMTP 发送邮件
 *
 * 信封鉴权在基类完成。参数：
 *   to          收件人，必填，逗号/分号分隔多个或 JSON 数组
 *   cc          抄送，可选，同上
 *   subject     主题，必填
 *   body        正文，必填（HTML 或纯文本）
 *   alt_body    HTML 邮件的纯文本备选，可选
 *   is_html     是否 HTML 正文，0/1，默认 0
 *   from_addr   覆盖发件人地址，可选（须与服务器配置匹配）
 *   from_name   覆盖发件人显示名，可选
 *   server_id   指定使用哪台已授权服务器；不传则用默认服务器
 */
class send extends index
{
    public function index()
    {
        $to = trim(Input::Post('to', false));
        $subject = trim(Input::Post('subject', false));
        $body = Input::Post('body', false);

        if ($to === '') {
            Ret::Fail(400, null, '缺少参数[to]');
        }
        if ($subject === '') {
            Ret::Fail(400, null, '缺少参数[subject]');
        }
        if ($body === '') {
            Ret::Fail(400, null, '缺少参数[body]');
        }

        $cc = trim(Input::Post('cc', false, false));
        $isHtml = Input::PostInt('is_html', false) === 1 ? 1 : 0;
        $serverId = Input::PostInt('server_id', false);

        $projectId = (int)($this->proc['id'] ?? 0);
        if ($serverId > 0) {
            $server = MailServerModel::authorizedServer($projectId, $serverId);
            if (empty($server)) {
                Ret::Fail(403, null, '指定的邮件服务器未授权给本项目或已禁用');
            }
        } else {
            $server = MailServerModel::defaultServer($projectId);
            if (empty($server)) {
                Ret::Fail(403, null, '项目未授权可用的邮件服务器');
            }
        }

        $mail = [
            'to'        => $to,
            'cc'        => $cc,
            'subject'   => $subject,
            'body'      => $body,
            'alt_body'  => Input::Post('alt_body', false, false),
            'is_html'   => $isHtml,
            'from_addr' => trim(Input::Post('from_addr', false, false)),
            'from_name' => trim(Input::Post('from_name', false, false)),
        ];

        $log = [
            'kid'         => $this->kid,
            'project_id'  => $projectId,
            'server_id'   => (int)$server['id'],
            'server_name' => (string)$server['name'],
            'to_addr'     => $to,
            'cc_addr'     => $cc,
            'subject'     => $subject,
        ];

        try {
            $messageId = MailAction::send($server, $mail);
            $log['success'] = 1;
            MailAction::writeLog($log);
            Ret::Success(0, ['message_id' => $messageId], '邮件已提交发送');
        } catch (Exception $e) {
            $log['success'] = 0;
            $log['error'] = $e->getMessage();
            MailAction::writeLog($log);
            Ret::Fail(500, null, $e->getMessage());
        }
    }
}
