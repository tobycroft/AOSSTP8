<?php

use think\facade\Request;

class Input
{
    public static function PostFloat(string $name, bool $must_have = true): float
    {
        $in = Request::post($name, null, 'float');
        if (!$in) {
            if ($must_have) {
                Ret::Fail(400, null, 'Input-Post-Float:[' . $name . ']');
            }
            return 0;
        } elseif (!is_float($in)) {
            Ret::Fail(400, null, 'Input-Post-Float:[' . $name . '] is not float');
            return 0;
        } else {
            return $in;
        }
    }

    public static function Post(string $name, bool $must_have = true, bool $xss = false): string
    {
        if (!Request::has($name, "post") && $must_have) {
            Ret::Fail(400, null, "Input-Post:[" . $name . "]");
        }
        if ($xss) {
            return strval(request()->post($name, '', 'strip_tags'));
        } else {
            return strval(request()->post($name));
        }
    }

    public static function PostBool(string $name, bool $must_have = true): bool
    {
        $in = Request::post($name, null, 'bool');
        if (!$in) {
            if ($must_have) {
                Ret::Fail(400, null, 'Input-Post-Bool:[' . $name . ']');
            }
            return 0;
        } elseif (!is_bool($in)) {
            Ret::Fail(400, null, 'Input-Post-Bool:[' . $name . '] is not boolean');
            return 0;
        } else {
            return $in;
        }
    }

    public static function PostInt(string $name, bool $must_have = true): int
    {
        $in = Request::post($name, null, 'int');
        if (!$in) {
            if ($must_have) {
                Ret::Fail(400, null, 'Input-Post-Int:[' . $name . ']');
            }
            return 0;
        } elseif (!is_int($in)) {
            Ret::Fail(400, null, 'Input-Post-Int:[' . $name . '] is not integer');
            return 0;
        } else {
            return intval($in);
        }
    }

    public static function PostDateTime(string $name, bool $must_have = true): string|null
    {
        $in = Request::post($name);
        if (!$in && $must_have) {
            Ret::Fail(400, null, 'Input-Post-DateTime:[' . $name . ']');
            return null;
        } else {
            $time = strtotime($in);
            if ($time) {
                return date(DATE_RFC3339, $time);
            } else {
                Ret::Fail(400, null, 'Input-Post-DateTime:[' . $name . '] is not DateTime');
                return null;
            }
        }
    }

    public static function PostJson(string $name, bool $must_have = true): array
    {
        if (!Request::has($name) && $must_have) {
            Ret::Fail(400, null, 'Input-Post-Json:[' . $name . ']');
        }
        $in = strval(request()->post($name));
        if ($json = json_decode($in, true)) {
            return $json;
        } else {
            Ret::Fail(400, null, 'Input-Post-Json:[' . $name . '] should be json string');
            return [];
        }
    }

    public static function Combi(string $name, bool $must_have = true, bool $xss = false): string
    {
        if (Request::has($name, "post")) {
            return self::Post($name, $must_have, $xss);
        } else {
            return self::Get($name, $must_have, $xss);
        }
    }

    public static function Get(string $name, bool $must_have = true, bool $xss = false): string
    {
        if (!Request::has($name, "get") && $must_have) {
            Ret::Fail(400, null, 'Input-Get:[' . $name . ']');
            return "";
        }
        if ($xss) {
            return strval(request()->get($name, '', 'strip_tags'));
        } else {
            return strval(request()->get($name));
        }
    }

    public static function Raw(): string
    {
        return request()->getInput();
    }

}

function removeXSS($data)
{

    $_clean_xss_config = HTMLPurifier_Config::createDefault();
    $_clean_xss_config->set('Core.Encoding', 'UTF-8');
    // 保留的标签
    $_clean_xss_config->set('HTML.Allowed', 'div,b,strong,i,em,a[href|title],ul,ol,li,p[style],br,span[style],img[width|height|alt|src]');
    $_clean_xss_config->set('CSS.AllowedProperties', 'font,font-size,font-weight,font-style,font-family,text-decoration,padding-left,color,background-color,text-align');
    $_clean_xss_config->set('HTML.TargetBlank', TRUE);
    $_clean_xss_obj = new HTMLPurifier($_clean_xss_config);
    return $_clean_xss_obj->purify($data);

}