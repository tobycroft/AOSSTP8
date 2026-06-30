<?php

namespace app\v2\file\controller;

use app\v1\file\model\AttachmentModel;
use app\v1\oss\model\OssModel;
use app\v2\file\model\FileTokenModel;
use BaseController\CommonController;
use Input;
use Ret;

class token extends CommonController
{
    public function create()
    {
        $token = Input::Post('token');
        $timestamp = Input::Post('timestamp');
        $sign = Input::Post('sign');

        $now = time();
        if (abs($now - intval($timestamp)) > 300) {
            Ret::Fail(400, null, '请求已过期');
        }

        $oss = OssModel::api_find_token($token);
        if (!$oss) {
            Ret::Fail(401, null, '项目不存在');
        }

        $expected_sign = md5($token . $timestamp);
        if ($sign !== $expected_sign) {
            Ret::Fail(401, null, '签名验证失败');
        }

        $temp_token = md5(uniqid('ft_', true) . $token . microtime());
        $expired_at = date('Y-m-d H:i:s', $now + 300);

        FileTokenModel::create([
            'token' => $temp_token,
            'oss_token' => $token,
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
        $token = Input::Post('token');
        $timestamp = Input::Post('timestamp');
        $sign = Input::Post('sign');

        $now = time();
        if (abs($now - intval($timestamp)) > 300) {
            Ret::Fail(400, null, '请求已过期');
        }

        $oss = OssModel::api_find_token($token);
        if (!$oss) {
            Ret::Fail(401, null, '项目不存在');
        }

        $expected_sign = md5($token . $timestamp);
        if ($sign !== $expected_sign) {
            Ret::Fail(401, null, '签名验证失败');
        }

        Ret::Success(0, [
            'upload_url' => 'https://upload.tuuz.cc:433/v2/file/index/upfull',
        ]);
    }

    public function upload_url_hash()
    {
        $token = Input::Post('token');
        $timestamp = Input::Post('timestamp');
        $sign = Input::Post('sign');

        $now = time();
        if (abs($now - intval($timestamp)) > 300) {
            Ret::Fail(400, null, '请求已过期');
        }

        $oss = OssModel::api_find_token($token);
        if (!$oss) {
            Ret::Fail(401, null, '项目不存在-'.$token);
        }

        $expected_sign = md5($token . $timestamp);
        if ($sign !== $expected_sign) {
            Ret::Fail(401, null, '签名验证失败');
        }

        Ret::Success(0, [
            'upload_url' => 'https://upload.tuuz.cc:433/v2/file/index/uphash',
        ]);
    }

    public function hash_query()
    {
        $token = Input::Post('token');
        $timestamp = Input::Post('timestamp');
        $sign = Input::Post('sign');
        $hash = Input::Post('hash');

        $now = time();
        if (abs($now - intval($timestamp)) > 300) {
            Ret::Fail(400, null, '请求已过期');
        }

        $oss = OssModel::api_find_token($token);
        if (!$oss) {
            Ret::Fail(401, null, '项目不存在');
        }

        $expected_sign = md5($token . $timestamp);
        if ($sign !== $expected_sign) {
            Ret::Fail(401, null, '签名验证失败');
        }

        if (empty($hash)) {
            Ret::Fail(400, null, '需要hash字段');
        }

        $file = AttachmentModel::where('md5', $hash)->find();
        if (!$file) {
            Ret::Fail(404, null, '未找到文件');
        }

        $file['src'] = $file['path'];
        $file['url'] = $oss['url'] . '/' . $file['path'];
        $file['surl'] = $file['path'];

        Ret::Success(0, $file);
    }
}