<?php

namespace app\v1\qwen\controller;


use app\v1\image\controller\create;
use app\v1\nlp\model\NlpModel;
use app\v1\qwen\model\QwenModel;
use Qwen\Enums\Models;
use Qwen\QwenClient;
use think\Response;

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
        $response = QwenClient::build($this->qwen['appkey'], 'https://dashscope.aliyuncs.com')
            ->query('我说aaa，然后你说bbb')
//            ->query('今天是2025年1月2日', 'assistant')
//            ->query('你好，今天是几月几日?')
            ->withModel('qwen2.5-1.5b-instruct')
//            ->withModel('deepseek-r1-distill-llama-8b')
            ->run();

//        echo $response;
        Response::create($response)->send();
    }
}