<?php

namespace app\v1\live\struct;

class AllUrl
{
    public string $rtmp = '';
    public string $domain = '';
    public string $play_domain = '';
    public string $obs_server = '';
    public string $stream_code = '';
    public string $webrtc = '';
    public string $srt = '';
    public string $rtmp_over_srt = '';

    public string $play_flv = "";
    public string $play_hls = "";
    public string $play_rtmp = "";


    public function __construct($domain, $play_domain, $streamName, $moban, $push_key, $play_key, $time)
    {
        $txTime = strtoupper(base_convert(strtotime($time), 10, 16));
        $txPushSecret = md5($push_key . $streamName . $txTime);
        $txPlaySecret = md5($play_key . $streamName . $txTime);
        $push_str = '?' . http_build_query(array(
                'txSecret' => $txPushSecret,
                'txTime' => $txTime
            ));
        $play_str = '?' . http_build_query(array(
                'txSecret' => $txPlaySecret,
                'txTime' => $txTime
            ));
        $this->domain = $domain;
        $this->play_domain = $play_domain;
        $this->obs_server = 'rtmp://' . $domain . '/live/';
        $this->stream_code = $streamName . ($push_str ?? '');
        $this->rtmp = 'rtmp://' . $this->domain . '/live/' . $this->stream_code;
        $this->rtmp_over_srt = 'rtmp://' . $this->domain . ':3570/live/' . $this->stream_code;
        $this->srt = 'srt://' . $this->domain . ':9000?streamid=#!::h=' . $this->domain . ',r=live/' . $streamName . ',txSecret=' . $txPushSecret . ',txTime=' . $txTime;
        $this->webrtc = 'webrtc://' . $this->domain . '/live/' . $this->stream_code;
        if (!empty($moban)) $streamName .= '_' . $moban;
        $this->play_flv = '' . $this->play_domain . '/live/' . $streamName . '.flv' . $play_str;
        $this->play_hls = '' . $this->play_domain . '/live/' . $streamName . '.m3u8' . $play_str;
        $this->play_rtmp = 'rtmp://' . $this->play_domain . '/live/' . $streamName . '.m3u8' . $play_str;
    }
}