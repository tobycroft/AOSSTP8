<?php

namespace app\v3\ai\controller;

use app\v3\ai\action\AiAction;
use Input;
use Ret;
use think\Exception;

/**
 * v3 图片生成接口（文生图 / 图生图 / 多图合成）
 *
 * POST /v3/ai/images/generations
 * 继承 index 复用凭证信封鉴权，上游走项目绑定的 agnes API 的 api_key / base_url。
 */
class images extends index
{
    public function generations()
    {
        $prompt = Input::Post('prompt');
        if ($prompt === '') {
            Ret::Fail(400, null, '缺少参数prompt');
        }
        $size = Input::Post('size');
        if ($size === '') {
            Ret::Fail(400, null, '缺少参数size');
        }

        $options = [];
        // 显式指定模型；缺省由 AiAction 用独立图片默认模型，不回退到对话模型
        if (request()->has('model', 'post')) {
            $options['model'] = Input::Post('model');
        }
        if (request()->has('ratio', 'post')) {
            $options['ratio'] = Input::Post('ratio');
        }
        if (request()->has('return_base64', 'post')) {
            $options['return_base64'] = Input::PostBool('return_base64');
        }
        if (request()->has('response_format', 'post')) {
            $options['response_format'] = Input::Post('response_format');
        }
        // 输入图像数组：图生图 / 多图合成必填，JSON 字符串提交
        if (request()->has('image', 'post')) {
            $options['image'] = Input::PostJson('image');
        }
        // 高级工作流附加参数，JSON 字符串提交
        if (request()->has('extra_body', 'post')) {
            $options['extra_body'] = Input::PostJson('extra_body');
        }

        try {
            $data = AiAction::imageGenerations($this->proc, $prompt, $size, $options);
            Ret::Success(0, $data);
        } catch (Exception $e) {
            Ret::Fail(500, null, $e->getMessage());
        }
    }
}
