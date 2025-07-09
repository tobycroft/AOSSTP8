<?php

namespace app\v2\image\action;

use Imagick;
use ImagickDraw;
use ImagickPixel;
use think\Exception;

class DataAction
{
    private $item;
    public $x = 0;
    public $y = 0;
    public $position = 'LT'; // 默认左上角

    /**
     * @throws Exception
     */
    public function __construct($item)
    {
        $this->item = $item;
        if (!isset($this->item['type'])) {
            throw new Exception('type');
        }
        $this->x = $this->item['x'] ?? 0;
        $this->y = $this->item['y'] ?? 0;
        $this->position = $this->item['position'] ?? 'LT';
    }

    /**
     * @throws Exception
     */
    public function handle(): ?Imagick
    {
        switch ($this->item['type']) {
            case 'text':
                return $this->createTextLayer();
            case 'image':
                return $this->createImageLayer();
            default:
                return null;
        }
    }

    /**
     * 创建文本图层
     * @throws Exception
     */
    private function createTextLayer(): Imagick
    {
        // 参数校验
        if (!isset($this->item['text'])) throw new Exception('text');

        $text = $this->item['text'];
        $size = $this->item['size'] ?? 24;      // 默认字号
        $color = $this->item['color'] ?? 'black'; // 默认颜色
        $font = $this->item['font'] ?? null;    // 字体文件路径

        // 创建绘图对象
        $draw = new ImagickDraw();
        $draw->setFontSize($size);
        $draw->setFillColor(new ImagickPixel($color));
        if ($font && file_exists($font)) {
            $draw->setFont($font);
        }

        // 计算文本尺寸
        $metrics = (new Imagick())->queryFontMetrics($draw, $text);
        $width = $metrics['textWidth'] + 10;  // 增加边距
        $height = $metrics['textHeight'] + 10;

        // 创建透明画布
        $layer = new Imagick();
        $layer->newImage($width, $height, new ImagickPixel('transparent'));
        $layer->setImageFormat('png');

        // 绘制文本（垂直居中）
        $layer->annotateImage(
            $draw,
            5, // 水平偏移
            $metrics['ascender'] + 5, // 垂直居中
            0,
            $text
        );

        return $layer;
    }

    /**
     * 创建图片图层
     * @throws Exception
     */
    private function createImageLayer(): Imagick
    {
        if (!isset($this->item['url'])) throw new Exception('url');

        $url = $this->item['url'];
        $layer = new Imagick();

        try {
            if (filter_var($url, FILTER_VALIDATE_URL)) {
                // 处理网络图片
                $layer->readImage($url);
            } elseif (file_exists($url)) {
                // 处理本地路径
                $layer->readImage($url);
            } else {
                throw new Exception('Invalid image source');
            }

            // 处理多帧图片（如GIF）
            if ($layer->getNumberImages() > 1) {
                $layer = $layer->coalesceImages();
                $layer->setIteratorIndex(0);
            }

            // 转换为PNG格式保持透明度
            $layer->setImageFormat('png');
            return $layer;

        } catch (\ImagickException $e) {
            throw new Exception('Image load failed: ' . $e->getMessage());
        }
    }
}