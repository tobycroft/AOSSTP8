<?php

namespace app\v2\file\controller;

use app\v1\file\model\AttachmentModel;
use app\v1\oss\model\OssModel;
use BaseController\CommonController;
use Input;
use Ret;

class token extends CommonController
{
    /**
     * 按 MD5 查询文件详情。
     *
     * v1/v2 上传入口已全部下线（v2 临时令牌签发与 upfull/uphash 上传一并移除），
     * 仅保留该查询能力，上传请使用 v3 文件接口。
     */
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
