<?php

namespace app\v1\image\controller;


use app\v1\file\action\OssSelectionAction;
use app\v1\image\action\DataAction;
use app\v1\oss\model\OssModel;
use BaseController\CommonController;
use Input;
use OSS\AliyunOSS;
use OSS\Core\OssException;
use PHPImageWorkshop\ImageWorkshop;
use Ret;
use SendFile\SendFile;
use think\Exception;
use think\facade\Response;
use think\Request;

class create extends CommonController
{


    public null|string $token = null;
    public mixed $proc;
    protected int $width;
    protected int $height;
    protected string $background;

    public function initialize()
    {
        set_time_limit(0);
        parent::initialize();
        if (!$this->token) {
            $this->token = Input::Combi('token');
        }
        $this->proc = OssModel::api_find_token($this->token);
        if (!$this->proc) {
            Ret::Fail(401, null, '项目不可用');
        }
    }

    public function canvas(Request $request)
    {
        $this->proc = OssSelectionAction::App_find_byProc($this->proc);
        if (!$request->has("width")) {
            Ret::Fail(400, null, "width");
        }
        if (!$request->has("height")) {
            Ret::Fail(400, null, "height");
        }
        if (!$request->has("background")) {
            Ret::Fail(400, null, "background");
        }
        $this->width = input("width");
        $this->height = input("height");
        $this->background = input("background");
        $json = $request->post("data");
        $data = json_decode($json, 1);
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
        $image = $document->getResult($this->background);
        $document->delete();
        imagejpeg($image, null, 95);
        Response::contentType("image/png")->send();
    }

    public function file(Request $request)
    {
        $this->proc = OssSelectionAction::App_find_byProc($this->proc);
        if (!$request->has("width")) {
            Ret::Fail(400, null, "width");
        }
        if (!$request->has("height")) {
            Ret::Fail(400, null, "height");
        }
        if (!$request->has("background")) {
            Ret::Fail(400, null, "background");
        }
        $this->width = input("width");
        $this->height = input("height");
        $this->background = input("background");
        $json = $request->post("data");
        $data = json_decode($json, 1);
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