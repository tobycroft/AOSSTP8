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
        $valid = $ai->checkCookie();
        var_dump($valid);

        $subject = 'Internet memes';
        $tone = 'funny';
        $type = 'blog post';
        $length = 'short';

        $prompt = new Prompt("Please write a *$length* *$type* in a *$tone* style about `$subject`. Please wrap the $type in a markdown codeblock.");

        var_dump($conversation->ask($prompt->withoutCache()));
    }
}