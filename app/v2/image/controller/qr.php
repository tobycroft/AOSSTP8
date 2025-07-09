<?php

namespace app\v2\image\controller;

use app\v1\file\action\OssSelectionAction;
use app\v2\image\action\QRImageWithLogo;
use app\v1\oss\model\OssModel;
use BaseController\CommonController;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Ret;
use think\facade\Response;
use think\Request;

class qr extends index
{


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

