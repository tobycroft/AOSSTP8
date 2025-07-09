<?php

namespace app\v1\image\controller;

use app\v1\file\action\OssSelectionAction;
use app\v1\image\action\QRImageWithLogo;
use app\v1\oss\model\OssModel;
use BaseController\CommonController;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
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
        $opt = new QROptions([
            'version' => 11,
            'eccLevel' => QRCode::ECC_L,
            'scale' => 7,
            'imageBase64' => false,
            'bgColor' => [200, 200, 200],
            'imageTransparent' => false,
            'drawCircularModules' => true,
            'circleRadius' => 0.8,
        ]);
        $qr = new QRCode($opt);

        return $qr->render($data);
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
        $opt = new QROptions([
            'version' => 10,
            'eccLevel' => QRCode::ECC_H,
            'scale' => 7,
            'imageBase64' => false,
            'bgColor' => [255, 255, 255],
            'imageTransparent' => false,
            'drawCircularModules' => true,
            'circleRadius' => 0.8,
            'addLogoSpace' => true,
        ]);
        $qr = new QRCode($opt);
        $mat = $qr->getMatrix($json);
//        $mat->setLogoSpace(10, 10, null, null);

        $qrp = new QRImageWithLogo($opt, $mat);
        echo $qrp->dump(null, $url);
        Response::contentType("image/png")->send();
    }

}

