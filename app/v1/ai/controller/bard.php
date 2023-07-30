<?php

namespace app\v1\ai\controller;

use BaseController\CommonController;

class bard extends CommonController
{

    public function index()
    {

//two keys are required which are two cookies values
        $_ENV['BARD_API_KEY_X'] = " value of cookie 'YwjH_fEZPXksdEqJUyzkxO6DKrRSR2ew2pu8jtZqbA6WQLJ6DKHamg.' ";
        $_ENV['BARD_API_KEY_Y'] = " value of cookie 'sidts-CjEBPusdKTHUqUCyFVlOhZxTWfbvSxF5ONToTXp8hEAA' ";
        $bard = new \Pj8912\PhpBardApi\Bard();
        $input_text = 'Hello, Bard!';  // Input text for the conversation
        $result = $bard->get_answer($input_text);  // Get the response from Bard

// bard reply
        print($result['choices'][0]['content'][0]);
    }
}