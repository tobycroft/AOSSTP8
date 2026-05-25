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
        // 获取基础参数（单位：mm）
        $width_mm = Input::Post('width');
        $height_mm = Input::Post('height');
        $background = Input::Post('background');
        $data = Input::PostJson('data');
        $dpi = Input::PostInt('dpi');

        // 毫米转像素（1英寸=25.4毫米）
        $width_px = round(($width_mm * $dpi) / 25.4);
        $height_px = round(($height_mm * $dpi) / 25.4);

        // 创建画布（使用像素尺寸）
        $canvas = new Imagick();
        $canvas->newImage($width_px, $height_px, new ImagickPixel($background));
        $canvas->setImageResolution($dpi, $dpi);
        $canvas->setImageUnits(Imagick::RESOLUTION_PIXELSPERINCH);

        foreach ($data as $item) {
            try {
                // 将DPI传递给DataAction
                $handler = new DataAction($item, $dpi);
                $layer = $handler->handle();

                if ($layer) {
                    // 将位置参数的毫米单位转换为像素
                    $x_px = round(($handler->x * $dpi) / 25.4);
                    $y_px = round(($handler->y * $dpi) / 25.4);

                    // 计算实际坐标（使用像素单位）
                    list($x, $y) = $this->calculatePosition(
                        $handler->position,
                        $x_px,
                        $y_px,
                        $layer->getImageWidth(),
                        $layer->getImageHeight(),
                        $width_px,
                        $height_px
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
        switch (strtoupper($position)) {
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
            case 'LB':
            case 'LD': // 左下
                return [$x, $canvasHeight - $layerHeight - $y];
            case 'CB':
            case 'CD': // 中下
                return [
                    ($canvasWidth - $layerWidth) / 2 + $x,
                    $canvasHeight - $layerHeight - $y
                ];
            case 'RB': // 右下
            case 'RD': // 右下
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

        // 新增：获取DPI参数（默认72）
        $dpi = Input::PostInt('dpi', 72);

        // 毫米转像素（保留原有参数单位，但转换为像素）
        $width_px = round(($this->width * $dpi) / 25.4);
        $height_px = round(($this->height * $dpi) / 25.4);

        // === 修改部分：使用Imagick替代ImageWorkshop ===
        $canvas = new Imagick();
        $canvas->newImage($width_px, $height_px, new ImagickPixel($this->background));
        $canvas->setImageResolution($dpi, $dpi);
        $canvas->setImageUnits(Imagick::RESOLUTION_PIXELSPERINCH);

        foreach ($data as $item) {
            try {
                // 传递DPI给DataAction
                $handler = new DataAction($item, $dpi);
                $layer = $handler->handle();

                if ($layer instanceof \Imagick) {
                    // 毫米转像素
                    $x_px = round(($handler->x * $dpi) / 25.4);
                    $y_px = round(($handler->y * $dpi) / 25.4);

                    // 计算实际位置（复用canvas方法中的逻辑）
                    list($x, $y) = $this->calculatePosition(
                        $handler->position,
                        $x_px,
                        $y_px,
                        $layer->getImageWidth(),
                        $layer->getImageHeight(),
                        $width_px,
                        $height_px
                    );

                    // 合成图层
                    $canvas->compositeImage(
                        $layer,
                        Imagick::COMPOSITE_DEFAULT,
                        $x,
                        $y
                    );
                    $layer->clear(); // 释放资源
                }
            } catch (Exception $e) {
                Ret::Fail(300, null, $e->getMessage());
            }
        }
        // === Imagick部分结束 ===

        // 保持原有的MD5生成逻辑不变
        $crypt = [
            'width' => $this->width,
            'height' => $this->height,
            'background' => $this->background,
            'data' => $data
        ];
        $md5 = md5(json_encode($crypt, 320));

        // 创建保存目录
        $saveDir = './upload/image/' . $this->token;
        if (!is_dir($saveDir)) {
            mkdir($saveDir, 0777, true);
        }

        // 修改：使用Imagick保存图片
        $path_name = "{$saveDir}/{$md5}.jpg";
        $canvas->setImageFormat('jpg');
        $canvas->writeImage($path_name);
        $canvas->clear(); // 释放资源

        $fileName = 'image/' . $this->token . '/' . $md5 . '.jpg';

        // 保持原有的存储逻辑不变
        $sav = 'https://image.tuuz.cc:444//image/' . $this->token . DIRECTORY_SEPARATOR . $md5 . '.jpg';
        Ret::Success(0, $sav);
    }

}