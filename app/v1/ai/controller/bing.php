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
        $prompt = new Prompt('Hello World');
        list($text, $cards) = $conversation->ask($prompt, function ($text, $cards) use (&$padding) {
            // Erase last line
            echo str_repeat(chr(8), $padding);

            $text = trim($text);

            // Print partial answer
            echo "- $text";
            $padding = mb_strlen($text) + 2;
        });

        // Erase last line
        echo str_repeat(chr(8), $padding);

        // Print final answer
        echo "- $text" . PHP_EOL;
    }
}