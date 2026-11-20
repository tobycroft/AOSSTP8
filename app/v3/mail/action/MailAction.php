<?php

namespace app\v3\mail\action;

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;
use think\Exception;

/**
 * 邮件发送动作：基于 PHPMailer 的 SMTP 客户端封装
 *
 * 服务器配置取自项目授权（ao_v3_project_mail → ao_mail_server），
 * 发信结果与异常统一写入 ao_mail_log，便于后台审计排障。
 */
class MailAction
{
    /** 正文最大长度（字节），防止超大请求滥用 SMTP 配额 */
    public const MAX_BODY = 1024 * 1024;

    /** 主题最大长度 */
    public const MAX_SUBJECT = 200;

    /**
     * 发送一封邮件
     *
     * @param array $server 邮件服务器配置（MailServerModel::defaultServer 返回）
     * @param array $mail   to, cc, subject, body, alt_body, is_html, from_addr, from_name
     * @return string Message-ID
     * @throws Exception 发信失败时抛出，message 可直接回显
     */
    public static function send(array $server, array $mail): string
    {
        if (empty($server['host']) || empty($server['port'])) {
            throw new Exception('邮件服务器配置不完整');
        }

        $mailer = new PHPMailer(true);
        try {
            $mailer->CharSet = 'UTF-8';
            $mailer->isSMTP();
            $mailer->Host = (string)$server['host'];
            $mailer->Port = (int)$server['port'];
            $mailer->SMTPAuth = !empty($server['username']);
            if ($mailer->SMTPAuth) {
                $mailer->Username = (string)$server['username'];
                $mailer->Password = (string)$server['password'];
            }

            $encryption = strtolower((string)($server['encryption'] ?? 'none'));
            if ($encryption === 'ssl') {
                $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($encryption === 'tls') {
                $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }
            // 25 端口明文或 auto 场景：不显式设置 SMTPSecure

            $mailer->Timeout = 30;

            // 发件人：请求可覆盖，缺省用服务器配置的默认地址
            $fromAddr = (string)($mail['from_addr'] ?? '') ?: (string)($server['from_addr'] ?? '');
            if ($fromAddr === '') {
                throw new Exception('未配置发件人地址');
            }
            $fromName = (string)($mail['from_name'] ?? '') ?: (string)($server['from_name'] ?? '');
            $mailer->setFrom($fromAddr, $fromName);

            foreach (self::parseAddresses($mail['to'] ?? '') as $addr) {
                $mailer->addAddress($addr);
            }
            foreach (self::parseAddresses($mail['cc'] ?? '') as $addr) {
                $mailer->addCC($addr);
            }

            $subject = (string)($mail['subject'] ?? '');
            $body = (string)($mail['body'] ?? '');
            if (mb_strlen($subject) > self::MAX_SUBJECT) {
                throw new Exception('邮件主题过长');
            }
            if (strlen($body) > self::MAX_BODY) {
                throw new Exception('邮件正文过长');
            }

            $isHtml = !empty($mail['is_html']);
            $mailer->isHTML($isHtml);
            $mailer->Subject = $subject;
            $mailer->Body = $body;
            if ($isHtml) {
                $alt = (string)($mail['alt_body'] ?? '');
                $mailer->AltBody = $alt !== '' ? $alt : strip_tags($body);
            }

            $mailer->send();
            return (string)$mailer->getLastMessageID();
        } catch (MailException $e) {
            throw new Exception('邮件发送失败: ' . self::toUtf8($e->getMessage()) . ' | ' . self::toUtf8($mailer->ErrorInfo));
        } finally {
            $mailer->smtpClose();
        }
    }

    /**
     * 将非 UTF-8 文本归一化为 UTF-8，避免非法字节进入 JSON 响应。
     * 国内 SMTP 服务器错误信息常为 GBK 编码，直接拼接会导致 json_encode 失败。
     */
    private static function toUtf8(string $text): string
    {
        if ($text === '' || mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }
        // 先从常见 GBK 转；转换后仍非法则用替换符兜底，杜绝非法字节外泄
        $converted = mb_convert_encoding($text, 'UTF-8', 'GBK');
        return mb_check_encoding($converted, 'UTF-8')
            ? $converted
            : mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }

    /**
     * 写发送日志（成功与失败都调用）
     */
    public static function writeLog(array $log): void
    {
        \think\facade\Db::name('ao_mail_log')->insert([
            'kid'         => (string)($log['kid'] ?? ''),
            'project_id'  => (int)($log['project_id'] ?? 0),
            'server_id'   => (int)($log['server_id'] ?? 0),
            'server_name' => (string)($log['server_name'] ?? ''),
            'to_addr'     => mb_substr((string)($log['to_addr'] ?? ''), 0, 255),
            'cc_addr'     => mb_substr((string)($log['cc_addr'] ?? ''), 0, 255),
            'subject'     => mb_substr((string)($log['subject'] ?? ''), 0, 255),
            'success'     => !empty($log['success']) ? 1 : 0,
            'error'       => mb_substr((string)($log['error'] ?? ''), 0, 1000),
        ]);
    }

    /**
     * 解析收件人：支持逗号/分号分隔或 JSON 数组
     *
     * @return string[] 合法邮箱地址
     */
    private static function parseAddresses(mixed $raw): array
    {
        if (is_array($raw)) {
            $items = $raw;
        } else {
            $text = (string)$raw;
            if ($text !== '' && $text[0] === '[') {
                $decoded = json_decode($text, true);
                $items = is_array($decoded) ? $decoded : [$text];
            } else {
                $items = preg_split('/[,;]/', $text) ?: [];
            }
        }

        $out = [];
        foreach ($items as $item) {
            $addr = trim((string)$item);
            if ($addr !== '' && filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                $out[] = $addr;
            }
        }
        return $out;
    }
}
