<?php

namespace app\v2\file\controller;

use app\v2\file\model\FileTokenModel;
use app\v2\project\model\ProjectModel;
use BaseController\CommonController;
use Input;
use Ret;

class token extends CommonController
{
    public function create()
    {
        $appid = Input::Post('appid');
        $timestamp = Input::Post('timestamp');
        $sign = Input::Post('sign');

        $now = time();
        if (abs($now - intval($timestamp)) > 300) {
            Ret::Fail(400, null, '请求已过期');
        }

        $project = (new ProjectModel)->api_find_appid($appid);
        if (!$project) {
            Ret::Fail(401, null, '项目不存在');
        }

        $expected_sign = md5($appid . $project['open_token'] . $timestamp);
        if ($sign !== $expected_sign) {
            Ret::Fail(401, null, '签名验证失败');
        }

        $temp_token = md5(uniqid('ft_', true) . $project['open_token'] . microtime());
        $expired_at = date('Y-m-d H:i:s', $now + 300);

        FileTokenModel::create([
            'token' => $temp_token,
            'project_token' => $project['open_token'],
            'created_at' => date('Y-m-d H:i:s', $now),
            'expired_at' => $expired_at,
            'is_used' => 0,
        ]);

        Ret::Success(0, [
            'token' => $temp_token,
            'expired_at' => $expired_at,
        ]);
    }

    public function upload_url()
    {
        $appid = Input::Post('appid');
        $timestamp = Input::Post('timestamp');
        $sign = Input::Post('sign');

        $now = time();
        if (abs($now - intval($timestamp)) > 300) {
            Ret::Fail(400, null, '请求已过期');
        }

        $project = (new ProjectModel)->api_find_appid($appid);
        if (!$project) {
            Ret::Fail(401, null, '项目不存在');
        }

        $expected_sign = md5($appid . $project['open_token'] . $timestamp);
        if ($sign !== $expected_sign) {
            Ret::Fail(401, null, '签名验证失败');
        }

        Ret::Success(0, [
            'upload_url' => 'https://upload.tuuz.cc:433/v2/file/index/upfull',
        ]);
    }
}