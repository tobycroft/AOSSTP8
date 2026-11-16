<?php

namespace app\v3\ai\controller;

use app\v3\ai\action\AiAction;
use Input;
use Ret;
use think\Exception;

class messages extends index
{
    public function index()
    {
        $messages = Input::PostJson('messages');
        if (empty($messages)) {
            Ret::Fail(400, null, '缺少参数messages');
        }

        $options = [];
        if (request()->has('model', 'post')) {
            $options['model'] = Input::Post('model');
        }
        if (request()->has('temperature', 'post')) {
            $options['temperature'] = Input::PostFloat('temperature');
        }
        if (request()->has('max_tokens', 'post')) {
            $options['max_tokens'] = Input::PostInt('max_tokens');
        }
        if (request()->has('top_p', 'post')) {
            $options['top_p'] = Input::PostFloat('top_p');
        }
        if (request()->has('stream', 'post')) {
            $options['stream'] = Input::PostBool('stream');
        }

        try {
            $data = AiAction::messages($this->proc, $messages, $options);
            Ret::Success(0, $data);
        } catch (Exception $e) {
            Ret::Fail(500, null, $e->getMessage());
        }
    }
}