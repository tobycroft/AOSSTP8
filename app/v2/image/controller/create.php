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
        // 获取基础参数
        $width = Input::Combi('width');
        $height = Input::Combi('height');
        $background = Input::Combi('background');
        $data = Input::PostJson('data');
        $dpi = (int)Input::Combi('dpi', 203);

        // 创建画布
        $canvas = new Imagick();
        $canvas->newImage($width, $height, new ImagickPixel($background));
        $canvas->setImageResolution($dpi, $dpi);
        $canvas->setImageUnits(Imagick::RESOLUTION_PIXELSPERINCH);

        foreach ($data as $item) {
            try {
                $handler = new DataAction($item);
                $layer = $handler->handle();

                if ($layer) {
                    // 根据位置参数计算实际坐标
                    list($x, $y) = $this->calculatePosition(
                        $handler->position,
                        $handler->x,
                        $handler->y,
                        $layer->getImageWidth(),
                        $layer->getImageHeight(),
                        $width,
                        $height
                    );

                    // 合成图层
                    $canvas->compositeImage(
                        $layer,
                        Imagick::COMPOSITE_DEFAULT,
                        $x,
                        $y
                    );

                    $layer->clear(); // 及时释放资源
                }

            } catch (Exception $e) {
                Ret::Fail(300, null, $e->getMessage());
            }
        }

        // 设置输出格式
        $canvas->setImageFormat('png');
        response($canvas->getImageBlob())->contentType('image/png')->send();

        // 清理资源
        $canvas->clear();
    }

    /**
     * 根据位置标识计算实际坐标
     */
    private function calculatePosition(
        string $position,
        float  $x,
        float  $y,
        float  $layerWidth,
        float  $layerHeight,
        float  $canvasWidth,
        float  $canvasHeight
    ): array
    {
        switch ($position) {
            case 'CT': // 中上
                return [($canvasWidth - $layerWidth) / 2 + $x, $y];
            case 'RT': // 右上
                return [$canvasWidth - $layerWidth - $x, $y];
            case 'LC': // 左中
                return [$x, ($canvasHeight - $layerHeight) / 2 + $y];
            case 'CC': // 中心
                return [
                    ($canvasWidth - $layerWidth) / 2 + $x,
                    ($canvasHeight - $layerHeight) / 2 + $y
                ];
            case 'RC': // 右中
                return [
                    $canvasWidth - $layerWidth - $x,
                    ($canvasHeight - $layerHeight) / 2 + $y
                ];
            case 'LB': // 左下
                return [$x, $canvasHeight - $layerHeight - $y];
            case 'CB': // 中下
                return [
                    ($canvasWidth - $layerWidth) / 2 + $x,
                    $canvasHeight - $layerHeight - $y
                ];
            case 'RB': // 右下
                return [
                    $canvasWidth - $layerWidth - $x,
                    $canvasHeight - $layerHeight - $y
                ];
            default: // LT 左上及其他情况
                return [$x, $y];
        }
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