<?php

namespace app\v3\ai\action;

use think\Exception;

class AiAction
{
    protected static string $default_base_url = 'https://apihub.agnes-ai.com/v1';
    protected static string $default_model = 'agnes-3.0-flash';

    /** 图片生成默认模型：与对话模型相互独立，切勿回退到 $default_model */
    protected static string $default_image_model = 'agnes-image-2.5-flash';

    /** 返回数据中 model 字段的统一别名，避免向调用方暴露上游真实模型名 */
    protected const MASKED_MODEL = 'aoss';

    public static function getDefaultBaseUrl(): string
    {
        return self::$default_base_url;
    }

    public static function getDefaultModel(): string
    {
        return self::$default_model;
    }

    public static function getDefaultImageModel(): string
    {
        return self::$default_image_model;
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
        return self::postOpenAI($url, $api_key, $payload);
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
        return self::postOpenAI($url, $api_key, $payload);
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
        return self::postAnthropic($url, $api_key, $payload);
    }

    /**
     * 图片生成（文生图 / 图生图 / 多图合成）
     *
     * 上游：POST {base_url}/images/generations，Bearer 鉴权，与 chat 同一套配置。
     * 与对话接口的关键差异：
     *  - 默认模型使用独立的图片模型，绝不回退到 $proc['model']（那是对话模型）；
     *  - 依接口「重要说明」：response_format 与输入图像一律放进 extra_body，
     *    请求体顶层不出现 response_format（即便 extra 里误传也会下沉纠正）。
     *
     * @param array  $proc    已鉴权的项目配置（含绑定 agnes API 合并进来的 api_key / base_url / model）
     * @param string $prompt  图像生成或编辑的文本指令
     * @param string $size    尺寸档位（1K/2K/3K/4K）或历史精确尺寸
     * @param array  $options model / ratio / image(string[]) / response_format /
     *                        return_base64 / extra_body / extra
     * @return array 上游原样响应（data[0].url 或 data[0].b64_json）
     */
    public static function imageGenerations(array $proc, string $prompt, string $size, array $options = []): array
    {
        $base_url = $proc['base_url'] ?: self::$default_base_url;
        // 图片模型与对话模型隔离：只认显式传入的 model，否则用图片默认值
        $model = ($options['model'] ?? '') ?: self::$default_image_model;
        $api_key = $proc['api_key'];

        $payload = [
            'model' => $model,
            'prompt' => $prompt,
            'size' => $size,
        ];

        if (!empty($options['ratio'])) {
            $payload['ratio'] = $options['ratio'];
        }
        if (isset($options['return_base64'])) {
            $payload['return_base64'] = (bool)$options['return_base64'];
        }

        // extra_body：先接收调用方原始透传，再写入规范化字段（同名以规范化为准）
        $extra_body = (!empty($options['extra_body']) && is_array($options['extra_body']))
            ? $options['extra_body']
            : [];

        // 图生图 / 多图合成：输入图像统一放 extra_body.image
        if (!empty($options['image']) && is_array($options['image'])) {
            $extra_body['image'] = array_values($options['image']);
        }
        // response_format 只允许出现在 extra_body（url / b64_json）
        if (!empty($options['response_format'])) {
            $extra_body['response_format'] = $options['response_format'];
        }

        // extra 作为顶层高级透传，兼容其它方法习惯；但禁止把 response_format 留在顶层
        if (!empty($options['extra']) && is_array($options['extra'])) {
            $extra = $options['extra'];
            if (isset($extra['response_format'])) {
                $extra_body['response_format'] = $extra['response_format'];
                unset($extra['response_format']);
            }
            $payload = array_merge($payload, $extra);
        }

        if (!empty($extra_body)) {
            $payload['extra_body'] = $extra_body;
        }

        $url = rtrim($base_url, '/') . '/images/generations';
        return self::postOpenAI($url, $api_key, $payload);
    }

    protected static function postOpenAI(string $url, string $api_key, array $payload): array
    {
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ];
        return self::postJson($url, $headers, $payload);
    }

    protected static function postAnthropic(string $url, string $api_key, array $payload): array
    {
        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . $api_key,
            'anthropic-version: 2023-06-01',
        ];
        return self::postJson($url, $headers, $payload);
    }

    protected static function postJson(string $url, array $headers, array $payload): array
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
        curl_close($ch);

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
            self::emitSse((string)$response);
        }

        // 非流式响应：仅替换顶层 model，不递归改写嵌套业务字段
        if (!is_array($data)) {
            throw new Exception('AI接口返回格式异常');
        }
        if (array_key_exists('model', $data)) {
            $data['model'] = self::MASKED_MODEL;
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
     */
    protected static function emitSse(string $raw): void
    {
        $masked = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            if (strpos($line, 'data:') === 0) {
                $payload = ltrim(substr($line, 5));
                if ($payload !== '' && $payload !== '[DONE]') {
                    $chunk = json_decode($payload, true);
                    if (is_array($chunk) && array_key_exists('model', $chunk)) {
                        $chunk['model'] = self::MASKED_MODEL;
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