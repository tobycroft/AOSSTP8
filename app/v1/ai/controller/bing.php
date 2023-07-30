<?php

namespace app\v1\ai\controller;

use app\v1\system\model\SystemParamModel;
use MaximeRenou\BingAI\BingAI;
use MaximeRenou\BingAI\Chat\Prompt;

class bing
{

    public function index()
    {
        // $cookie - your "_U" cookie from bing.com
        $ai = new BingAI(SystemParamModel::api_find_key('bing_cookie'));

        $conversation = $ai->createChatConversation();

// $text - Text-only version of Bing's answer
// $cards - Message objects array
        list($text, $cards) = $conversation->ask(new Prompt('Hello World'));
        list($final_text, $final_cards) = $conversation->ask($prompt, function ($text, $cards) {
            echo $text;
        });
    }
}