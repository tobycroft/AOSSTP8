<?php

namespace app\v1\live\struct;

class PushUrl
{
    public string $rtmp = "";
    public string $domain = "";
    public string $obs_server = "";
    public string $stream_code = "";
    public string $webrtc = "";
    public string $srt = "";
    public string $rtmp_over_srt = "";


    public function __construct($domain, $streamName, $key, $time)
    {
        $txTime = strtoupper(base_convert(strtotime($time), 10, 16));
        $txSecret = md5($key . $streamName . $txTime);
        $ext_str = '?' . http_build_query(array(
                'txSecret' => $txSecret,
                'txTime' => $txTime
            ));
        $this->domain = $domain;
        $this->obs_server = 'rtmp://' . $domain . '/live/';
        $this->stream_code = $streamName . (isset($ext_str) ? $ext_str : '');
        $this->rtmp = 'rtmp://' . $this->domain . '/live/' . $this->stream_code;
        $this->rtmp_over_srt = 'rtmp://' . $this->domain . ':3570/live/' . $this->stream_code;
        $this->srt = 'srt://' . $this->domain . ':9000?streamid=#!::h=' . $this->domain . ',r=live/' . $streamName . ',txSecret=' . $txSecret . ',txTime=' . $txTime;
        $this->webrtc = 'webrtc://' . $this->domain . '/live/' . $this->stream_code;
    }
}