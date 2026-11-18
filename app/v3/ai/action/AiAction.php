<?php

namespace app\v3\ai\action;

use think\Exception;

class AiAction
{
    protected static string $default_base_url = 'https://apihub.agnes-ai.com/v1';
    protected static string $default_model = 'agnes-3.0-flash';

    /** 缺省别名：项目未配置 model_alias 时回退到此值 */
    protected const MASKED_MODEL = 'aoss';

    /**
     * 解析对外返回的模型名别名：优先取项目自定义值，缺省回退 MASKED_MODEL
     *
     * 空串、纯空白、非标量一律视为未配置。model_alias 字段可能尚未随迁移脚本
     * 落到库中（本机连不上数据库），此处用 ?? '' 兜底，保证旧库也能正常运行。
     */
    protected static function resolveMaskedModel(array $proc): string
    {
        $alias = $proc['model_alias'] ?? '';
        if (!is_string($alias)) {
            return self::MASKED_MODEL;
        }
        $alias = trim($alias);
        return $alias !== '' ? $alias : self::MASKED_MODEL;
    }

    public static function getDefaultBaseUrl(): string
    {
        return self::$default_base_url;
    }

    public static function getDefaultModel(): string
    {
        return self::$default_model;
    }

    public static function chatCompletions(array $proc, array $messages, array $options = []): array
    {
        $base_url = $proc['base_url'] ?: self::$default_base_url;
        $model = ($options['model'] ?? '') ?: (($proc['model'] ?? '') ?: self::$default_model);
        $api_key = $proc['api_key'];

        $payload = [
            'model' => $model,
            'messages' => $messages,
        ];

        if (isset($options['temperature'])) {
            $payload['temperature'] = $options['temperature'];
        }
        if (isset($options['top_p'])) {
            $payload['top_p'] = $options['top_p'];
        }
        if (isset($options['max_tokens'])) {
            $payload['max_tokens'] = (int)$options['max_tokens'];
        }
        if (isset($options['stream'])) {
            $payload['stream'] = (bool)$options['stream'];
        }
        if (!empty($options['tools'])) {
            $payload['tools'] = $options['tools'];
        }
        if (!empty($options['tool_choice'])) {
            $payload['tool_choice'] = $options['tool_choice'];
        }
        if (!empty($options['chat_template_kwargs'])) {
            $payload['chat_template_kwargs'] = $options['chat_template_kwargs'];
        }
        if (!empty($options['extra']) && is_array($options['extra'])) {
            $payload = array_merge($payload, $options['extra']);
        }

        $url = rtrim($base_url, '/') . '/chat/completions';
        return self::postOpenAI($url, $api_key, $payload, self::resolveMaskedModel($proc));
    }

    public static function responses(array $proc, $input, array $options = []): array
    {
        $base_url = $proc['base_url'] ?: self::$default_base_url;
        $model = ($options['model'] ?? '') ?: (($proc['model'] ?? '') ?: self::$default_model);
        $api_key = $proc['api_key'];

        $payload = [
            'model' => $model,
            'input' => $input,
        ];

        if (isset($options['max_output_tokens'])) {
            $payload['max_output_tokens'] = (int)$options['max_output_tokens'];
        }
        if (isset($options['temperature'])) {
            $payload['temperature'] = $options['temperature'];
        }
        if (isset($options['top_p'])) {
            $payload['top_p'] = $options['top_p'];
        }
        if (isset($options['stream'])) {
            $payload['stream'] = (bool)$options['stream'];
        }
        if (!empty($options['instructions'])) {
            $payload['instructions'] = $options['instructions'];
        }
        if (!empty($options['chat_template_kwargs'])) {
            $payload['chat_template_kwargs'] = $options['chat_template_kwargs'];
        }
        if (!empty($options['extra']) && is_array($options['extra'])) {
            $payload = array_merge($payload, $options['extra']);
        }

        $url = rtrim($base_url, '/') . '/responses';
        return self::postOpenAI($url, $api_key, $payload, self::resolveMaskedModel($proc));
    }

    /**
     * Messages API（Anthropic-compatible）
     *
     * 推理开关按上游 Anthropic 兼容格式使用 thinking 字段：
     *   {"type": "enabled", "budget_tokens": 2048}
     * 不传 thinking 即为不带推理模式，与 chat/responses 的
     * chat_template_kwargs（OpenAI 格式）相互独立，切勿混用。
     */
    public static function messages(array $proc, array $messages, array $options = []): array
    {
        $base_url = $proc['base_url'] ?: self::$default_base_url;
        $model = ($options['model'] ?? '') ?: (($proc['model'] ?? '') ?: self::$default_model);
        $api_key = $proc['api_key'];

        $payload = [
            'model' => $model,
            'max_tokens' => (int)($options['max_tokens'] ?? 1024),
            'messages' => $messages,
        ];

        if (!empty($options['system'])) {
            $payload['system'] = $options['system'];
        }
        if (isset($options['temperature'])) {
            $payload['temperature'] = $options['temperature'];
        }
        if (isset($options['top_p'])) {
            $payload['top_p'] = $options['top_p'];
        }
        if (isset($options['stream'])) {
            $payload['stream'] = (bool)$options['stream'];
        }
        if (!empty($options['chat_template_kwargs'])) {
            $payload['chat_template_kwargs'] = $options['chat_template_kwargs'];
        }
        // Thinking 模式（Anthropic-compatible）：{"type":"enabled","budget_tokens":2048}
        if (!empty($options['thinking'])) {
            $payload['thinking'] = $options['thinking'];
        }
        if (!empty($options['extra']) && is_array($options['extra'])) {
            $payload = array_merge($payload, $options['extra']);
        }

        $url = rtrim($base_url, '/') . '/messages';
        return self::postAnthropic($url, $api_key, $payload, self::resolveMaskedModel($proc));
    }

    protected static function postOpenAI(string $url, string $api_key, array $payload, string $maskedModel): array
    {
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ];
        return self::postJson($url, $headers, $payload, $maskedModel);
    }

    protected static function postAnthropic(string $url, string $api_key, array $payload, string $maskedModel): array
    {
        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . $api_key,
            'anthropic-version: 2023-06-01',
        ];
        return self::postJson($url, $headers, $payload, $maskedModel);
    }

    protected static function postJson(string $url, array $headers, array $payload, string $maskedModel): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, 320));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($response === false) {
            throw new Exception('AI接口请求失败: ' . $error);
        }

        $data = json_decode($response, true);
        if ($http_code < 200 || $http_code >= 300) {
            $err_msg = $data['error']['message'] ?? ($data['message'] ?? 'HTTP ' . $http_code);
            throw new Exception('AI接口返回错误: ' . $err_msg);
        }

        // stream=true 时上游返回 SSE 文本流，json_decode 为 null，交由流式处理器按块屏蔽
        if (($payload['stream'] ?? false) === true) {
            self::emitSse((string)$response, $maskedModel);
        }

        // 非流式响应：仅替换顶层 model，不递归改写嵌套业务字段
        if (!is_array($data)) {
            throw new Exception('AI接口返回格式异常');
        }
        if (array_key_exists('model', $data)) {
            $data['model'] = $maskedModel;
        }

        return $data;
    }

    /**
     * 流式响应处理：逐 data 块替换 model 后以标准 SSE 输出
     *
     * 上游 stream=true 返回 text/event-stream 文本，形如：
     *   data: {"id":"...","model":"agnes-3.0-flash","choices":[...]}
     *   data: [DONE]
     * 这里仅对 data: 且非 [DONE] 的 JSON 块替换其顶层 model，其余行原样透传。
     * 采用「接收完整响应后一次性输出」而非实时逐块推送，换取错误处理与非流式一致。
     *
     * @param string $raw         上游 SSE 原文
     * @param string $maskedModel 对外返回的模型名别名
     */
    protected static function emitSse(string $raw, string $maskedModel): void
    {
        $masked = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            if (strpos($line, 'data:') === 0) {
                $payload = ltrim(substr($line, 5));
                if ($payload !== '' && $payload !== '[DONE]') {
                    $chunk = json_decode($payload, true);
                    if (is_array($chunk) && array_key_exists('model', $chunk)) {
                        $chunk['model'] = $maskedModel;
                        $line = 'data: ' . json_encode($chunk, 320);
                    }
                }
            }
            $masked[] = $line;
        }

        // 丢弃已有缓冲，避免 Ret 之外的多余输出污染 SSE 流
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        header('Content-Type: text/event-stream; charset=utf-8', true);
        header('Cache-Control: no-cache', true);
        echo implode("\n", $masked);
        exit;
    }
}