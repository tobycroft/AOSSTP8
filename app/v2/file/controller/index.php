<?php

namespace app\v2\file\controller;

use app\v1\file\controller\index as V1Index;
use app\v1\oss\model\OssModel;
use app\v2\file\model\FileTokenModel;
use Input;
use Ret;
use think\Request;

class index extends V1Index
{
    public function initialize()
    {
        set_time_limit(0);
        header("Access-Control-Allow-Origin: *", true);
        header("Access-Control-Max-Age: 86400", true);
        header("Access-Control-Allow-Credentials: true", true);
        header("Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS", true);
        header("Access-Control-Allow-Headers: *", true);

        $temp_token = Input::Get('token');

        $file_token = (new FileTokenModel)->api_find_valid($temp_token);
        if (!$file_token) {
            Ret::Fail(401, null, '临时token无效或已过期');
        }

        $this->token = $file_token['oss_token'];
        $this->proc = OssModel::api_find_token($this->token);
        if (!$this->proc) {
            Ret::Fail(401, null, '项目不可用');
        }

        $file_token->is_used = 1;
        $file_token->save();
    }

    public function uphash(Request $request)
    {
        $this->upload_file($request, 1, 'hash');
    }
}