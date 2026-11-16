<?php

namespace app\v3\ai\controller;

use app\v3\ai\action\AiAction;
use Input;
use Ret;
use think\Exception;

class responses extends index
{
    public function index()
    {
        $input = Input::Post('input');
        if (empty($input)) {
            Ret::Fail(400, null, '缺少参数input');
        }

        $input_decoded = json_decode($input, true);
        if ($input_decoded === null) {
            $input_data = $input;
        } else {
            $input_data = $input_decoded;
        }

        $options = [];
        if (request()->has('model', 'post')) {
            $options['model'] = Input::Post('model');
        }
        if (request()->has('instructions', 'post')) {
            $options['instructions'] = Input::Post('instructions');
        }
        if (request()->has('temperature', 'post')) {
            $options['temperature'] = Input::PostFloat('temperature');
        }
        if (request()->has('max_output_tokens', 'post')) {
            $options['max_output_tokens'] = Input::PostInt('max_output_tokens');
        }
        if (request()->has('top_p', 'post')) {
            $options['top_p'] = Input::PostFloat('top_p');
        }
        if (request()->has('stream', 'post')) {
            $options['stream'] = Input::PostBool('stream');
        }

        try {
            $data = AiAction::responses($this->proc, $input_data, $options);
            Ret::Success(0, $data);
        } catch (Exception $e) {
            Ret::Fail(500, null, $e->getMessage());
        }
    }
}