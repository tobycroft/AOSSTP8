<?php

namespace app\v2\image\action;

use Imagick;
use ImagickDraw;
use ImagickPixel;
use think\Exception;


class DataAction
{
    private $item;
    private $dpi; // 存储DPI用于单位转换
    public $x = 0;
    public $y = 0;
    public $position = 'LT'; // 默认左上角

    /**
     * @throws Exception
     */
    public function __construct($item, $dpi)
    {
        $this->item = $item;
        $this->dpi = $dpi;

        if (!isset($this->item['type'])) {
            throw new Exception('type');
        }
        $this->x = $this->item['x'] ?? 0;
        $this->y = $this->item['y'] ?? 0;
        $this->position = $this->item['position'] ?? 'LT';
    }

    // ... handle() 方法保持不变 ...

    /**
     * 毫米转像素
     */
    private function mmToPx(float $mm): int
    {
        return round(($mm * $this->dpi) / 25.4);
    }

    /**
     * 创建文本图层（使用毫米单位）
     * @throws Exception
     */
    private function createTextLayer(): Imagick
    {
        // 参数校验
        if (!isset($this->item['text'])) throw new Exception('text');

        $text = $this->item['text'];
        $size_mm = $this->item['size'] ?? 6.35; // 默认6.35mm ≈ 18pt
        $color = $this->item['color'] ?? 'black';
        $font = $this->item['font'] ?? '../public/static/MiSans/MiSans VF.ttf';

        // 将毫米转换为点（1点 = 1/72英寸）
        $size_pt = ($size_mm * 72) / 25.4;

        // 创建绘图对象
        $draw = new ImagickDraw();
        $draw->setFontSize($size_pt);
        $draw->setFillColor(new ImagickPixel($color));
        if ($font && file_exists($font)) {
            $draw->setFont($font);
        }

        // 计算文本尺寸
        $metrics = (new Imagick())->queryFontMetrics($draw, $text);
        $width_px = $metrics['textWidth'] + $this->mmToPx(2); // 2mm边距
        $height_px = $metrics['textHeight'] + $this->mmToPx(2);

        // 创建透明画布
        $layer = new Imagick();
        $layer->newImage($width_px, $height_px, new ImagickPixel('transparent'));
        $layer->setImageFormat('png');

        // 绘制文本（垂直居中）
        $layer->annotateImage(
            $draw,
            $this->mmToPx(1), // 水平偏移1mm
            $metrics['ascender'] + $this->mmToPx(1), // 垂直偏移1mm
            0,
            $text
        );

        return $layer;
    }

    /**
     * 创建图片图层（支持毫米单位缩放）
     * @throws Exception
     */
    private function createImageLayer(): Imagick
    {
        if (!isset($this->item['url'])) throw new Exception('url');

        $url = $this->item['url'];
        $layer = new Imagick();

        try {
            if (filter_var($url, FILTER_VALIDATE_URL)) {
                $layer->readImage($url);
            } elseif (file_exists($url)) {
                $layer->readImage($url);
            } else {
                throw new Exception('Invalid image source');
            }

            // 处理多帧图片
            if ($layer->getNumberImages() > 1) {
                $layer = $layer->coalesceImages();
                $layer->setIteratorIndex(0);
            }

            // 添加毫米单位缩放支持
            if (isset($this->item['width_mm']) || isset($this->item['height_mm'])) {
                $width_px = $this->item['width_mm'] ? $this->mmToPx($this->item['width_mm']) : 0;
                $height_px = $this->item['height_mm'] ? $this->mmToPx($this->item['height_mm']) : 0;

                // 保持宽高比
                if ($width_px > 0 && $height_px > 0) {
                    $layer->resizeImage($width_px, $height_px, Imagick::FILTER_LANCZOS, 1);
                } else if ($width_px > 0) {
                    $layer->resizeImage($width_px, 0, Imagick::FILTER_LANCZOS, 1);
                } else if ($height_px > 0) {
                    $layer->resizeImage(0, $height_px, Imagick::FILTER_LANCZOS, 1);
                }
            }

            // 转换为PNG格式保持透明度
            $layer->setImageFormat('png');
            return $layer;

        } catch (\ImagickException $e) {
            throw new Exception('Image load failed: ' . $e->getMessage());
        }
    }
}