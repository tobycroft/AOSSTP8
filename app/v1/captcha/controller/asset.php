<?php

namespace app\v1\captcha\controller;

use BaseController\CommonController;
use Ret;

class asset extends CommonController
{
    public function js()
    {
        $file = app()->getRootPath() . 'public/static/captcha/click.js';
        if (!file_exists($file)) {
            Ret::Fail(404, null, 'JS 文件不存在');
        }
        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: public, max-age=86400');
        echo file_get_contents($file);
        exit;
    }

    public function css()
    {
        $file = app()->getRootPath() . 'public/static/captcha/click.css';
        if (!file_exists($file)) {
            Ret::Fail(404, null, 'CSS 文件不存在');
        }
        header('Content-Type: text/css; charset=utf-8');
        header('Cache-Control: public, max-age=86400');
        echo file_get_contents($file);
        exit;
    }
}