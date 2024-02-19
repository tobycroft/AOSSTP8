<?php

namespace app\v1\rcon\controller;

use app\v1\image\controller\create;
use app\v1\rcon\model\RconInfoModel;
use app\v1\rcon\model\RconModel;
use Kekalainen\GameRQ\Rcon\SourceRcon;
use Ret;

class index extends create
{

    public mixed $rcon;

    public mixed $rcon_info;


    public function initialize()
    {
        parent::initialize(); // TODO: Change the autogenerated stub
        $this->rcon = RconModel::where('project', $this->token)->find();
        if (!$this->rcon) {
            Ret::Fail(404, null, '未找到项目');
        }
        $this->rcon_info = RconInfoModel::where("tag", $this->rcon["tag"])->find();
        if (!$this->rcon_info) {
            Ret::Fail(404, null, '未找到项目详情');
        }
    }

}