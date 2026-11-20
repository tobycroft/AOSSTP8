<?php

namespace app\v3\mail\action;

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
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
     * 诊断式发信：分阶段收集 DNS 解析 / TCP 连接 / SMTP 会话的详细信息，
     * 供后台"测试连接"定位问题所在层级。全程不抛异常，错误纳入结果返回。
     *
     * @param array $server 邮件服务器配置
     * @param array $mail   to, subject, body, is_html 等（同 send）
     * @return array{ok:bool, stages:array, log:string, message_id:string, error:string}
     */
    public static function diagnose(array $server, array $mail): array
    {
        $host = (string)($server['host'] ?? '');
        $port = (int)($server['port'] ?? 0);
        $encryption = strtolower((string)($server['encryption'] ?? 'none'));
        $stages = [];

        // 阶段 1：DNS 解析（IP 直连则跳过）
        $stages[] = self::probeDns($host);

        // 阶段 2：TCP 连接（host:port 三次握手）
        $stages[] = self::probeTcp($host, $port, $encryption);

        // 阶段 3：SMTP 完整会话（开启协议日志 + 真实发信）
        $smtp = self::probeSmtp($server, $mail);
        $stages[] = $smtp['stage'];

        $ok = $smtp['ok'];
        return [
            'ok'         => $ok,
            'stages'     => $stages,
            'log'        => $smtp['log'],
            'message_id' => $smtp['message_id'],
            'error'      => $smtp['error'],
            'target'     => $host . ':' . $port,
            'encryption' => $encryption,
        ];
    }

    /**
     * 阶段1：DNS 解析探测
     */
    private static function probeDns(string $host): array
    {
        $t0 = microtime(true);
        if ($host === '') {
            return self::stage('DNS 解析', false, 0, '主机名为空');
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::stage('DNS 解析', true, 0, '目标为 IP 地址，无需解析：' . $host);
        }
        $ips = gethostbynamel($host);
        $ms = self::elapsed($t0);
        if (empty($ips)) {
            return self::stage('DNS 解析', false, $ms, '无法解析域名 ' . $host . '（检查 DNS 配置或域名拼写）');
        }
        $shown = array_slice($ips, 0, 5);
        return self::stage('DNS 解析', true, $ms, '解析到 ' . count($ips) . ' 个 IP：' . implode(', ', $shown));
    }

    /**
     * 阶段2：TCP 连接探测（按加密方式选择 transport，SSL 直连用 ssl://）
     */
    private static function probeTcp(string $host, int $port, string $encryption): array
    {
        $t0 = microtime(true);
        if ($host === '' || $port <= 0) {
            return self::stage('TCP 连接', false, 0, '主机或端口未配置');
        }
        $transport = ($encryption === 'ssl') ? 'ssl' : 'tcp';
        $errno = 0;
        $errstr = '';
        $ctx = stream_context_create([
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $fp = @stream_socket_client(
            $transport . '://' . $host . ':' . $port,
            $errno,
            $errstr,
            10,
            STREAM_CLIENT_CONNECT,
            $ctx
        );
        $ms = self::elapsed($t0);
        if ($fp === false) {
            return self::stage('TCP 连接', false, $ms, '无法连接 ' . $host . ':' . $port . ' [errno ' . $errno . '] ' . self::toUtf8($errstr));
        }
        fclose($fp);
        return self::stage('TCP 连接', true, $ms, '成功连接 ' . $host . ':' . $port . '（' . $transport . '）');
    }

    /**
     * 阶段3：SMTP 完整会话（协议日志 + 真实发信）
     *
     * @return array{ok:bool, stage:array, log:string, message_id:string, error:string}
     */
    private static function probeSmtp(array $server, array $mail): array
    {
        $t0 = microtime(true);
        $logLines = [];
        $mailer = new PHPMailer(true);
        // 捕获协议级日志（命令 + 响应），回调签名 (string $str, int $level)
        $mailer->SMTPDebug = SMTP::DEBUG_SERVER;
        $mailer->Debugoutput = function ($str) use (&$logLines) {
            $line = self::toUtf8(rtrim((string)$str, "\r\n"));
            if ($line !== '') {
                $logLines[] = $line;
            }
        };

        $error = '';
        $messageId = '';
        $ok = false;
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
            $mailer->Timeout = 30;

            $fromAddr = (string)($mail['from_addr'] ?? '') ?: (string)($server['from_addr'] ?? '');
            if ($fromAddr === '') {
                throw new Exception('未配置发件人地址');
            }
            $fromName = (string)($mail['from_name'] ?? '') ?: (string)($server['from_name'] ?? '');
            $mailer->setFrom($fromAddr, $fromName);
            foreach (self::parseAddresses($mail['to'] ?? '') as $addr) {
                $mailer->addAddress($addr);
            }
            $mailer->isHTML(!empty($mail['is_html']));
            $mailer->Subject = (string)($mail['subject'] ?? '');
            $mailer->Body = (string)($mail['body'] ?? '');

            $mailer->send();
            $messageId = (string)$mailer->getLastMessageID();
            $ok = true;
        } catch (MailException $e) {
            $error = self::toUtf8($e->getMessage()) . ' | ' . self::toUtf8($mailer->ErrorInfo);
        } catch (\Throwable $e) {
            $error = self::toUtf8($e->getMessage());
        } finally {
            @$mailer->smtpClose();
        }

        $ms = self::elapsed($t0);
        $stage = $ok
            ? self::stage('SMTP 会话', true, $ms, '认证与发信成功，Message-ID：' . $messageId)
            : self::stage('SMTP 会话', false, $ms, $error);
        return [
            'ok'         => $ok,
            'stage'      => $stage,
            'log'        => implode("\n", $logLines),
            'message_id' => $messageId,
            'error'      => $error,
        ];
    }

    /** 构造统一阶段结果 */
    private static function stage(string $label, bool $ok, int $ms, string $detail): array
    {
        return [
            'label'  => $label,
            'ok'     => $ok,
            'ms'     => $ms,
            'detail' => self::toUtf8($detail),
        ];
    }

    /** 计算耗时（毫秒） */
    private static function elapsed(float $t0): int
    {
        return (int)round((microtime(true) - $t0) * 1000);
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
            'subject'       => mb_substr((string)($log['subject'] ?? ''), 0, 255),
            'mail_type'     => (string)($log['mail_type'] ?? ''),
            'template_code' => (string)($log['template_code'] ?? ''),
            'success'       => !empty($log['success']) ? 1 : 0,
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
