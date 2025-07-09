<?php

namespace app\v2\image\action;

use PHPImageWorkshop\Core\ImageWorkshopLayer;
use think\Exception;

class DataAction extends Layer
{
    /**
     * @throws Exception
     */
    private $item;

    /**
     * @throws Exception
     */
    public function __construct($item)
    {
        $this->item = $item;
        if (!isset($this->item["type"])) {
            throw new Exception("type");
        }
        if (isset($this->item["x"])) {
            $this->x = $this->item["x"];
        }
        if (isset($this->item["y"])) {
            $this->y = $this->item["y"];
        }
        if (isset($this->item["position"])) {
            $this->position = $this->item["position"];
        }
    }

    /**
     * @throws Exception
     */
    public function handle(): ImageWorkshopLayer|null
    {
        switch ($this->item["type"]) {
            case "text":
                if (!isset($this->item["text"])) {
                    throw new Exception("text");
                }
                if (isset($this->item["size"])) {
                    $this->size = $this->item["size"];
                }

                $this->text = $this->item["text"];
                return $this->text();

            case "image":
                if (!isset($this->item["url"])) {
                    throw new Exception("url");
                }
                if (isset($this->item["x"])) {
                    $this->x = $this->item["x"];
                }
                if (isset($this->item["y"])) {
                    $this->y = $this->item["y"];
                }
                if (isset($this->item["position"])) {
                    $this->position = $this->item["position"];
                }
                $this->url = $this->item["url"];
                return $this->image();
        }
        return null;
    }
}

