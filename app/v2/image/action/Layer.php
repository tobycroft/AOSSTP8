<?php

namespace app\v1\image\action;


use PHPImageWorkshop\Core\ImageWorkshopLayer;
use PHPImageWorkshop\ImageWorkshop;

class Layer
{
    public string $position = "lt";
    public string $text = "";
    public int $size = 13;
    public mixed $x = 0;
    public mixed $y = 0;
    public string $url = "";

    private string $font = "../public/static/MiSans/MiSans VF.ttf";
    private string $font_color = "000000";

    public function text(): ImageWorkshopLayer
    {
        return ImageWorkshop::initTextLayer($this->text, $this->font, $this->size, $this->font_color);
    }

    public function image(): ImageWorkshopLayer
    {
        return ImageWorkshop::initFromPath($this->url);
    }

}