<?php

namespace app\v3\ai\action;

use think\Exception;

class AiAction
{
    protected static string $default_base_url = 'https://apihub.agnes-ai.com/v1';
    protected static string $default_model = 'agnes-3.0-flash';

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
        if (!empty($options['extra']) && is_array($options['extra'])) {
            $payload = array_merge($payload, $options['extra']);
        }

        $url = rtrim($base_url, '/') . '/messages';
        return self::postAnthropic($url, $api_key, $payload);
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

        if ($response === false) {
            throw new Exception('AI接口请求失败: ' . $error);
        }

        $data = json_decode($response, true);
        if ($http_code < 200 || $http_code >= 300) {
            $err_msg = $data['error']['message'] ?? ($data['message'] ?? 'HTTP ' . $http_code);
            throw new Exception('AI接口返回错误: ' . $err_msg);
        }

        return $data;
    }
}