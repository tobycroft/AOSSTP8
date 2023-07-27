<?php

namespace app\v1\live\struct;

class PlayUrl
{

    public string $play_domain = '';
    public string $play_flv = "";
    public string $play_hls = "";
    public string $play_rtmp = "";


    public function __construct($play_domain, $streamName, $moban, $play_key, $time)
    {
        $txTime = strtoupper(base_convert(strtotime($time), 10, 16));
        $txPlaySecret = md5($play_key . $streamName . $txTime);
        $play_str = '?' . http_build_query(array(
                'txSecret' => $txPlaySecret,
                'txTime' => $txTime
            ));
        if (!empty($moban)) $streamName .= '_' . $moban;
        $this->play_domain = $play_domain;
        $this->play_flv = '' . $this->play_domain . '/live/' . $streamName . '.flv' . $play_str;
        $this->play_hls = '' . $this->play_domain . '/live/' . $streamName . '.m3u8' . $play_str;
        $this->play_rtmp = 'rtmp://' . $this->play_domain . '/live/' . $streamName . '.m3u8' . $play_str;
    }
}