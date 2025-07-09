<?php

namespace app\v1\image\controller;

use think\Request;
use Zxing\QrReader;

class reader extends qr
{

    public function qr_url(Request $request)
    {
        if ($request->has("url"))
            \Ret::Fail(400, null, null);

        $url = input("url");
        $text = new QrReader($url);
        if ($text->getError() == null) {
            \Ret::Success(0, $text->text());
        } else {
            \Ret::Fail(300, null, $text->getError()->getMessage());
        }

    }
}