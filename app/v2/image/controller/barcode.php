<?php

namespace app\v2\image\controller;

use app\v1\file\action\OssSelectionAction;
use app\v1\oss\model\OssModel;
use app\v2\project\model\ProjectModel;
use BaseController\CommonController;
use Picqer\Barcode as bc;
use Ret;
use think\Request;
use think\Response;


class barcode extends index
{


    public mixed $token;
    public mixed $proc;

    public function initialize()
    {
        set_time_limit(0);
        parent::initialize();
        $this->token = \Input::Get('token');

        $this->proc = ProjectModel::api_find_token($this->token);
        if (!$this->proc) {
            Ret::Fail(401, null, '项目不可用');
        }
        $this->proc = OssSelectionAction::App_find_byProc($this->proc);
    }

    public function png(Request $request)
    {
        if (!$request->has("data")) {
            \Ret::Fail(400, null, 'data');
        }
        $json = input("data");
        $generator = new bc\BarcodeGeneratorPNG();
        echo $generator->getBarcode($json, $generator::TYPE_CODE_128);
        Response::contentType("image/png")->send();
    }


}