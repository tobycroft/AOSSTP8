<?php

namespace app\v1\qwen\controller;


use app\v1\image\controller\create;
use app\v1\nlp\model\NlpModel;
use app\v1\qwen\model\QwenModel;
use Qwen\Enums\Models;
use Qwen\QwenClient;

class index extends create
{

    protected $qwen = [];

    public function initialize()
    {
        parent::initialize(); // TODO: Change the autogenerated stub
        $project = QwenModel::where("project", $this->token)->findOrEmpty();
        if (empty($project)) {
            \Ret::Fail(404, null, "项目不存在");
        } else {
            $this->qwen = $project->toArray();
        }
    }

    public function aliyun()
    {
        $response = QwenClient::build($this->qwen['appkey'])
            ->query('Hello qwen, how are you today?')
            ->withModel('qwen2.5-1.5b-instruct')
            ->run();

        echo 'API Response:' . $response;
    }
}