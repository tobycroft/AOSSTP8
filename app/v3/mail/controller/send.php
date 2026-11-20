<?php

namespace app\v3\mail\controller;

use app\v3\mail\action\MailAction;
use app\v3\mail\action\MailContentAction;
use app\v3\mail\model\MailServerModel;
use app\v3\mail\model\MailTemplateModel;
use Input;
use Ret;
use think\Exception;

/**
 * v3 邮件发送：按内容类型提供三个入口，统一走信封鉴权与项目服务器授权。
 *
 *   POST /v3/mail/send/captcha    验证码：to, template, code
 *   POST /v3/mail/send/marketing  营销：to, template, vars
 *   POST /v3/mail/send/custom     自定义：to, subject, body
 *
 * 兼容入口：POST /v3/mail/send/index 等价于 custom（保留原有调用方）。
 *
 * 公共可选参数：cc, alt_body, from_addr, from_name, server_id。
 * is_html 由模板决定（captcha/marketing）；custom 仍由调用方传入。
 */
class send extends index
{
    /** 兼容旧入口：等价于自定义类型 */
    public function index()
    {
        $this->custom();
    }

    /**
     * 验证码邮件：只提供验证码，主题与正文由后台模板统一维护。
     */
    public function captcha()
    {
        $to = $this->requireTo();
        $template = $this->requireTemplate('captcha');
        try {
            $content = MailContentAction::renderCaptcha($template, Input::Post('code', false, false));
        } catch (Exception $e) {
            Ret::Fail(400, null, $e->getMessage());
        }
        $this->deliver($to, $content, 'captcha', (string)$template['code']);
    }

    /**
     * 营销邮件：后台模板统一维护，调用方只改关键变量（如链接、活动名）。
     */
    public function marketing()
    {
        $to = $this->requireTo();
        $template = $this->requireTemplate('marketing');

        $varsRaw = Input::Post('vars', false, false);
        $vars = json_decode((string)$varsRaw, true);
        if (!is_array($vars)) {
            Ret::Fail(400, null, '参数[vars]必须是 JSON 对象，例如 {"link":"https://example.com"}');
        }
        try {
            $content = MailContentAction::renderMarketing($template, $vars);
        } catch (Exception $e) {
            Ret::Fail(400, null, $e->getMessage());
        }
        $this->deliver($to, $content, 'marketing', (string)$template['code']);
    }

    /**
     * 自定义邮件：主题与正文完全由调用方提供。
     */
    public function custom()
    {
        $to = $this->requireTo();
        $subject = trim(Input::Post('subject', false));
        $body = Input::Post('body', false);
        if ($subject === '') {
            Ret::Fail(400, null, '缺少参数[subject]');
        }
        if ($body === '') {
            Ret::Fail(400, null, '缺少参数[body]');
        }

        $content = [
            'subject' => $subject,
            'body'    => $body,
            'is_html' => Input::PostInt('is_html', false) === 1 ? 1 : 0,
        ];
        $this->deliver($to, $content, 'custom', '');
    }

    /** 收件人必填校验 */
    private function requireTo(): string
    {
        $to = trim(Input::Post('to', false));
        if ($to === '') {
            Ret::Fail(400, null, '缺少参数[to]');
        }
        return $to;
    }

    /** 按类型取启用中的模板 */
    private function requireTemplate(string $type): array
    {
        $code = trim(Input::Post('template', false));
        if ($code === '') {
            Ret::Fail(400, null, '缺少参数[template]');
        }
        $template = MailTemplateModel::findEnabled($code, $type);
        if (empty($template)) {
            Ret::Fail(404, null, $type . ' 模板不存在或已停用');
        }
        return $template;
    }

    /**
     * 选取授权服务器 → 发信 → 写审计日志
     *
     * @param array  $content subject/body/is_html
     * @param string $type    captcha/marketing/custom
     */
    private function deliver(string $to, array $content, string $type, string $templateCode): void
    {
        $cc = trim(Input::Post('cc', false, false));
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
            'subject'   => $content['subject'],
            'body'      => $content['body'],
            'alt_body'  => Input::Post('alt_body', false, false),
            'is_html'   => $content['is_html'],
            'from_addr' => trim(Input::Post('from_addr', false, false)),
            'from_name' => trim(Input::Post('from_name', false, false)),
        ];

        $log = [
            'kid'           => $this->kid,
            'project_id'    => $projectId,
            'server_id'     => (int)$server['id'],
            'server_name'   => (string)$server['name'],
            'to_addr'       => $to,
            'cc_addr'       => $cc,
            'subject'       => $content['subject'],
            'mail_type'     => $type,
            'template_code' => $templateCode,
        ];

        try {
            $messageId = MailAction::send($server, $mail);
            $log['success'] = 1;
            MailAction::writeLog($log);
            Ret::Success(0, ['message_id' => $messageId, 'type' => $type], '邮件已提交发送');
        } catch (Exception $e) {
            $log['success'] = 0;
            $log['error'] = $e->getMessage();
            MailAction::writeLog($log);
            Ret::Fail(500, null, $e->getMessage());
        }
    }
}
