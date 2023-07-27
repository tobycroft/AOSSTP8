<?php

namespace app\v1\live\action;

use app\v1\live\struct\AllUrl;
use app\v1\live\struct\PlayUrl;
use app\v1\live\struct\PushUrl;

class GetUrl
{
    public static function getPushUrl($domain, $streamName, $key, $time): PushUrl
    {
        return new PushUrl($domain, $streamName, $key, $time);
    }

    public static function getAll($domain, $play_domain, $streamName, $moban, $push_key, $play_key, $time): AllUrl
    {
        return new AllUrl($domain, $play_domain, $streamName, $moban, $push_key, $play_key, $time);
    }

    public static function getPlayUrl($play_domain, $streamName, $moban, $play_key, $time): PlayUrl
    {
        return new PlayUrl($play_domain, $streamName, $moban, $play_key, $time);
    }

}