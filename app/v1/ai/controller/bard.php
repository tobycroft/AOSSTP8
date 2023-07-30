<?php

namespace app\v1\ai\controller;

use app\v1\system\model\SystemParamModel;
use BaseController\CommonController;

class bard extends CommonController
{

    public function index()
    {

//two keys are required which are two cookies values


        $_ENV['BARD_API_KEY_X'] = SystemParamModel::api_find_key('__Secure-1PSID');;
        $_ENV['BARD_API_KEY_Y'] = SystemParamModel::api_find_key("__Secure-1PSIDTS");
        $bard = new \Pj8912\PhpBardApi\Bard();
        $input_text = 'Hello, Bard!';  // Input text for the conversation
        $result = $bard->get_answer($input_text);  // Get the response from Bard
// bard reply
        print($result['choices'][0]['content'][0]);
    }
}