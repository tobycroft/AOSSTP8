<?php

class Ret
{
    public static function Fail(int $code = 400, mixed $data = [], string $echo = ''): void
    {
        self::Success($code, $data, $echo);
    }

    public static function Success(int $code = 0, mixed $data = [], string $echo = ''): void
    {
        if ($data === null) {
            $data = [];
        }
        header('Content-type: application/json');
        if (empty($echo)) {
            switch ($code) {
                case 0:
                    $echo = '成功';
                    break;

                case -1:
                    $echo = '登录失效请重新登录';
                    break;

                case 400:
                    $echo = '参数错误';
                    break;

                case 401:
                    $echo = '鉴权失败';
                    break;

                case 403:
                    $echo = '权限不足';
                    break;

                case 406 :
                case 407:
                    $echo = '数据不符合期待';
                    break;

                case 404:
                    $echo = '未找到数据';
                    break;

                case 500:
                    $echo = '数据库错误';
                    break;

                default:
                    $echo = '失败';
                    break;
            }
        }
        if (!isset($data)) {
            $data = [];
        }
        // JSON_INVALID_UTF8_SUBSTITUTE：下游文本（如 SMTP 返回的 GBK 错误消息）可能含非法
        // UTF-8 字节，若不加此标志 json_encode 会返回 false，echo 空串导致前端 JSON.parse
        // 报“响应解析失败”。此标志将非法字节替换为 U+FFFD，保证始终输出合法 JSON。
        echo json_encode([
            'code' => $code,
            'data' => $data,
            'echo' => $echo
        ], 320 | JSON_INVALID_UTF8_SUBSTITUTE);
        exit(0);
    }

}