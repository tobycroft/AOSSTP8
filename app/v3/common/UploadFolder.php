<?php

namespace app\v3\common;

/**
 * V3 Bucket 落盘 Path 的规范化与校验
 *
 * Bucket 的 Path 由后台在「存储管理 V3」里配置，最终会拼进
 * ./upload/{Path}/YYYYMMDD/md5.ext 的相对路径里，因此必须约束为
 * 「安全的相对目录段」，防止路径穿越到 upload/ 之外。
 *
 * 允许：字母、数字、下划线、连字符；多级用 / 分隔，如 a/b/c、site-2026。
 * 禁止：空段、. / ..、绝对路径、反斜杠、冒号及任何其它特殊字符。
 */
class UploadFolder
{
    /**
     * 规范化：去首尾空白；空值原样返回（表示回退到 Bucket 名称）
     */
    public static function normalize(?string $folder): string
    {
        return trim((string)$folder);
    }

    /**
     * 校验是否安全。返回 true/false。
     * 空字符串视为合法（表示未配置，回退 Bucket 名称）。
     */
    public static function isValid(?string $folder): bool
    {
        $folder = self::normalize($folder);
        if ($folder === '') {
            return true;
        }
        // 绝对路径 / 反斜杠 / 冒号（Windows 盘符、流）一律拒绝
        if ($folder[0] === '/' || str_contains($folder, '\\') || str_contains($folder, ':')) {
            return false;
        }
        $segments = explode('/', $folder);
        foreach ($segments as $seg) {
            if ($seg === '' || $seg === '.' || $seg === '..') {
                return false;
            }
            if (preg_match('/^[A-Za-z0-9_-]+$/', $seg) !== 1) {
                return false;
            }
        }
        return true;
    }

    /**
     * 落盘用的目录名：已配置则返回规范化值，否则回退到 Bucket 名称。
     * 调用方应保证入库前已通过 isValid 校验。
     */
    public static function resolve(?string $folder, string $fallback): string
    {
        $folder = self::normalize($folder);
        return $folder !== '' ? $folder : $fallback;
    }
}
