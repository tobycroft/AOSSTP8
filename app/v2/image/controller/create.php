<?php

namespace app\v2\image\controller;


use app\v2\image\action\DataAction;
use Imagick;
use ImagickPixel;
use Input;
use OSS\AliyunOSS;
use OSS\Core\OssException;
use PHPImageWorkshop\ImageWorkshop;
use Ret;
use SendFile\SendFile;
use think\Exception;
use think\Request;

class create extends index
{


    protected int $width;
    protected int $height;
    protected string $background;

    public function canvas(Request $request)
    {
        // 获取参数
        $this->width = Input::Combi('width');
        $this->height = Input::Combi('height');
        $this->background = Input::Combi('background');
        $data = Input::PostJson('data');
        $dpi = (int)Input::Combi('dpi', 203);

        // 直接创建 Imagick 画布作为基础层
        $canvas = new Imagick();
        $canvas->newImage($this->width, $this->height, new ImagickPixel($this->background), 'png');
        $canvas->setImageResolution($dpi, $dpi);
        $canvas->setImageUnits(Imagick::RESOLUTION_PIXELSPERINCH);

        // 处理所有图层
        foreach ($data as $item) {
            try {
                $layer_class = new DataAction($item);
                $layer = $layer_class->handle();

                // 将 GD 图层转换为 Imagick
                ob_start();
                imagepng($layer);
                $layerData = ob_get_clean();

                $layerImage = new Imagick();
                $layerImage->readImageBlob($layerData);

                // 将图层合成到画布上
                $canvas->compositeImage(
                    $layerImage,
                    Imagick::COMPOSITE_DEFAULT,
                    $layer_class->x,
                    $layer_class->y
                );

                // 清理资源
                $layerImage->destroy();
                imagedestroy($layer);

            } catch (Exception $e) {
                Ret::Fail(300, null, $e->getMessage());
            }
        }

        // 设置输出格式和质量
        $canvas->setImageFormat('jpeg');
        $canvas->setImageCompressionQuality(95);

        // 输出结果
        response($canvas->getImageBlob())->contentType('image/png')->send();
    }

    public function file(Request $request)
    {
        $this->width = Input::Combi('width');
        $this->height = Input::Combi('height');
        $this->background = Input::Combi('background');
        $data = Input::PostJson('data');
        $document = ImageWorkshop::initVirginLayer($this->width, $this->height);

        foreach ($data as $item) {
            try {
                $layer_class = new DataAction($item);
                $layer = $layer_class->handle();
                $document->addLayer(1, $layer, $layer_class->x, $layer_class->y, $layer_class->position);
            } catch (Exception $e) {
                Ret::Fail(300, null, $e->getMessage());
            }
        }
        $crypt = [
            "width" => $this->width,
            "height" => $this->height,
            "background" => $this->background,
            "data" => $data
        ];
        $md5 = md5(json_encode($crypt, 320));
        $document->getResult($this->background);
        $document->save("./upload/image/" . $this->token, $md5 . ".jpg");
        $path_name = "./upload/image/" . $this->token . "/" . $md5 . ".jpg";
        $fileName = "image/" . $this->token . "/" . $md5 . ".jpg";

        if ($this->proc["type"] == "local" || $this->proc["type"] == "all") {
            if ($this->proc['main_type'] == 'local') {
                $sav = $this->proc['url'] . "/image/" . $this->token . DIRECTORY_SEPARATOR . $md5 . ".jpg";
            }
        }
        if ($this->proc["type"] == "dp" || $this->proc["type"] == "all") {
            $sf = new SendFile();
            $ret = $sf->send('http://' . $this->proc["endpoint"] . '/up?token=' . $this->proc["bucket"], realpath('./upload/' . $fileName), "image/jpg", $md5 . "jpg");
            $json = json_decode($ret, 1);
            $sav = $this->proc['url'] . '/' . $json["data"];
        }
        if ($this->proc["type"] == "oss" || $this->proc["type"] == "all") {
            try {
                $oss = new AliyunOSS($this->proc);
                $ret = $oss->uploadFile($this->proc['bucket'], $fileName, $path_name);
            } catch (OssException $e) {
                Ret::Fail(200, null, $e->getMessage());
            }
            if (empty($ret->getData()["info"]["url"])) {
                Ret::Fail(300, null, "OSS不正常");
            }
            if ($this->proc['main_type'] == 'oss') {
                $sav = $this->proc['url'] . '/' . $fileName;
            }
            if ($this->proc["type"] != "all") {
                $document->delete();
                unlink($path_name);
            }
        }
        Ret::Success(0, $sav);
    }

}