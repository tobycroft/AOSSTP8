<?php

namespace app\v1\file\controller;

use app\v1\file\model\AttachmentModel;
use app\v1\oss\model\OssModel;
use BaseController\CommonController;
use Input;
use Ret;

class search extends CommonController
{

    protected mixed $token;
    protected mixed $proc;

    public function initialize()
    {
        parent::initialize();
        $this->token = Input::Get("token");
        $this->proc = OssModel::api_find_token($this->token);
        if (!$this->proc) {
            Ret::Fail(401, null, '项目不可用');
        }
    }

    public function md5()
    {
        $proc = $this->proc;
        $md5 = input("md5");
        if (empty($md5)) {
            Ret::Fail(400, null, "需要md5字段");
        }
        $file_exists = AttachmentModel::where("md5", $md5)->find();
        if (empty($file_exists)) {
            Ret::Fail(404, null, "未找到文件,请先上传");
        }
        if (!file_exists('./upload/' . $file_exists['path'])) {
            if ($proc['type'] == 'all') {
                Ret::Fail(404, null, '源文件已被删除,请重新上传');
            }
        }
        $file_exists["src"] = $file_exists['path'];
        $file_exists["url"] = $proc['url'] . '/' . $file_exists['path'];
        $file_exists["surl"] = $file_exists['path'];
        Ret::Success(0, $file_exists);
    }

    public function md5s()
    {
        $proc = $this->proc;
        $md5s = input("md5s");
        if (empty($md5s)) {
            Ret::Fail(400, null, "需要md5字段");
        }
        $d5s = json_decode($md5s, 1);
        $file_exists = AttachmentModel::whereIn("md5", $d5s)->select();
        if (empty($file_exists)) {
            Ret::Fail(404, null, "未找到文件,请先上传");
        }
        foreach ($file_exists as $key => $value) {
            if (!file_exists('./upload/' . $value['path'])) {
                if ($proc['type'] == 'all') {
                    Ret::Fail(404, null, '源文件已被删除,请重新上传');
                }
            }
            $value["src"] = $value['path'];
            $value["url"] = $proc['url'] . '/' . $value['path'];
            $value["surl"] = $value['path'];

            $file_exists[$key] = $value;
        }

        Ret::Success(0, $file_exists);
    }
}