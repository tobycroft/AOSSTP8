<?php

namespace app\v1\image\controller;

use app\v1\file\action\OssSelectionAction;
use app\v1\image\action\QRImageWithLogo;
use app\v1\oss\model\OssModel;
use BaseController\CommonController;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Color\Color;
use Ret;
use think\facade\Response;
use think\Request;

class qr extends CommonController
{


    public mixed $token;
    public mixed $proc;

    public function initialize()
    {
        set_time_limit(0);
        parent::initialize();
        $this->token = input('get.token');
        if (!$this->token) {
            \Ret::Fail(401, null, 'token');
        }
        $this->proc = OssModel::api_find_token($this->token);
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
        $json = input('data');
        echo base64_encode($this->qr_png($json));
    }

    public function qr_png($data)
    {
        echo $this->qr($data);
        Response::contentType('image/png')->send();

    }

    public function qr($data)
    {
        $qrCode = new QrCode(
            data: $data,
            errorCorrectionLevel: ErrorCorrectionLevel::Low,
            size: 300,
            margin: 10,
            foregroundColor: new Color(0, 0, 0),
            backgroundColor: new Color(200, 200, 200)
        );
        
        $writer = new PngWriter();
        $result = $writer->write($qrCode);

        return $result->getString();
    }

    public function base64(Request $request)
    {
        if (!$request->has("data")) {
            \Ret::Fail(400, null, 'data');
        }
        $json = input("data");
        echo base64_encode($this->qr($json));
    }

    public function logo(Request $request)
    {
        if (!$request->has("data")) {
            \Ret::Fail(400, null, 'data');
        }
        if (!$request->has("url")) {
            \Ret::Fail(400, null, "url");
        }
        $json = input("data");
        $url = input("url");
        
        $qrCode = new QrCode(
            data: $json,
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 400,
            margin: 15,
            foregroundColor: new Color(0, 0, 0),
            backgroundColor: new Color(255, 255, 255)
        );
        
        $logo = new \Endroid\QrCode\Logo\Logo(
            path: $url,
            resizeToWidth: 150,
            resizeToHeight: 150
        );
        
        $writer = new PngWriter();
        $result = $writer->write($qrCode, $logo);
        
        echo $result->getString();
        Response::contentType("image/png")->send();
    }

}