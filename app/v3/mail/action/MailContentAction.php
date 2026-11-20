<?php

namespace app\v3\mail\action;

use think\Exception;

/**
 * 邮件内容渲染：按模板类型把调用方提供的少量变量套进后台预置的主题/正文。
 *
 * 约定：
 *   captcha  —— 模板须含 {code}，调用方只传 1~16 位验证码；
 *   marketing —— 模板须含 {变量名}，调用方传 vars 逐项替换；
 *   custom   —— 不经本类，调用方直接提供 subject/body。
 *
 * 替换使用 strtr 单次扫描，变量值不会被二次解析；替换完成后检测残留占位符，
 * 避免用户收到带 {link} 字样的邮件。
 */
class MailContentAction
{
    /** 验证码最大长度 */
    public const MAX_CODE_LEN = 16;

    /** 营销变量单个值最大长度（字符） */
    public const MAX_VAR_LEN = 1000;

    /** 营销变量个数上限，防止超大表单与替换爆炸 */
    public const MAX_VAR_COUNT = 50;

    /** 主题/正文上限，与 MailAction 保持一致 */
    public const MAX_SUBJECT = 200;
    public const MAX_BODY = 1024 * 1024;

    /**
     * 渲染验证码模板
     *
     * @param array  $template 启用中的模板
     * @param string $code     验证码（1~16 位）
     * @return array{subject:string,body:string,is_html:int}
     */
    public static function renderCaptcha(array $template, string $code): array
    {
        $code = trim($code);
        if ($code === '') {
            throw new Exception('缺少参数[code]');
        }
        if (mb_strlen($code) > self::MAX_CODE_LEN) {
            throw new Exception('验证码长度不能超过 ' . self::MAX_CODE_LEN . ' 位');
        }

        $subject = (string)($template['subject'] ?? '');
        $body = (string)($template['body'] ?? '');
        if (!self::hasPlaceholder($subject) && !self::hasPlaceholder($body)) {
            throw new Exception('模板未配置 {code} 占位符，请先在邮件管理后台修正模板');
        }
        self::assertFilled($subject . $body, ['{code}' => $code]);

        return self::finish(
            strtr($subject, ['{code}' => $code]),
            strtr($body, ['{code}' => $code]),
            $template
        );
    }

    /**
     * 渲染营销模板
     *
     * @param array $template 启用中的模板
     * @param array $vars     变量名 => 值（标量）
     * @return array{subject:string,body:string,is_html:int}
     */
    public static function renderMarketing(array $template, array $vars): array
    {
        if (empty($vars)) {
            throw new Exception('营销邮件缺少参数[vars]，至少需要一个变量');
        }
        if (count($vars) > self::MAX_VAR_COUNT) {
            throw new Exception('营销变量最多 ' . self::MAX_VAR_COUNT . ' 个');
        }

        $map = [];
        foreach ($vars as $key => $value) {
            $key = (string)$key;
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                throw new Exception('营销变量名不合法：' . $key);
            }
            if (!is_scalar($value)) {
                throw new Exception('营销变量[' . $key . ']必须是字符串或数字');
            }
            $text = (string)$value;
            if (mb_strlen($text) > self::MAX_VAR_LEN) {
                throw new Exception('营销变量[' . $key . ']长度不能超过 ' . self::MAX_VAR_LEN . ' 字符');
            }
            $map['{' . $key . '}'] = $text;
        }

        $subject = (string)($template['subject'] ?? '');
        $body = (string)($template['body'] ?? '');
        if (!self::hasPlaceholder($subject) && !self::hasPlaceholder($body)) {
            throw new Exception('模板未配置占位符，请先在邮件管理后台修正模板');
        }
        self::assertFilled($subject . $body, $map);

        return self::finish(strtr($subject, $map), strtr($body, $map), $template);
    }

    /** 模板中是否含任意 {变量} 占位符 */
    private static function hasPlaceholder(string $text): bool
    {
        return preg_match('/\{[A-Za-z_][A-Za-z0-9_]*\}/', $text) === 1;
    }

    /**
     * 校验模板中出现的所有占位符都能被本次变量覆盖。
     * 只扫描模板原文，避免变量值本身含花括号时被误判为缺失。
     */
    private static function assertFilled(string $templateText, array $map): void
    {
        if (preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $templateText, $m)) {
            $missing = [];
            foreach ($m[1] as $name) {
                if (!array_key_exists('{' . $name . '}', $map)) {
                    $missing[$name] = $name;
                }
            }
            if (!empty($missing)) {
                throw new Exception('模板占位符未提供值：{' . implode('}, {', array_keys($missing)) . '}');
            }
        }
    }

    /**
     * 长度校验，组装最终邮件内容
     */
    private static function finish(string $subject, string $body, array $template): array
    {
        if (mb_strlen($subject) > self::MAX_SUBJECT) {
            throw new Exception('邮件主题过长');
        }
        $len = strlen($body);
        if ($len === 0) {
            throw new Exception('模板正文为空，请先在邮件管理后台修正模板');
        }
        if ($len > self::MAX_BODY) {
            throw new Exception('邮件正文过长');
        }

        return [
            'subject' => $subject,
            'body'    => $body,
            'is_html' => !empty($template['is_html']) ? 1 : 0,
        ];
    }
}
