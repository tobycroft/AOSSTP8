<?php

namespace Aoss;

use CURLFile;

class Aoss
{
    public string $remote_url = 'https://upload.tuuz.cc:4444';
    protected string $send_url;
    protected string $send_path = "/v1/file/index";
    protected string $send_token = "?token=";
    protected string $token;
    protected string $mode;

    public function __construct($token, $mode = "complete", $remote_url = "")
    {
        $this->send_url = $remote_url;
        $this->token = $token;
        $this->mode = $mode;

        if (empty($remote_url)) {
            $this->send_url = $this->remote_url;
            $this->send_url .= $this->send_path . "/up_complete";
            $this->send_url .= $this->send_token . $this->token;
        }
    }

    public static function raw_post($send_url, $postData): bool|string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $send_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        $response = curl_exec($ch);
        return $response;
    }

    public static function curl_send_file($real_path, $mime_type, $file_name, $send_url): string|bool
    {
        $postData = [
            'file' => new CURLFile(realpath($real_path), $mime_type, $file_name)
        ];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $send_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        $response = curl_exec($ch);
        return $response;
    }

    public function send($real_path, $mime_type, $file_name): AossSimpleRet|AossCompleteRet
    {
        return match ($this->mode) {
            "complete" => self::send_file_complete($this->send_url, $real_path, $mime_type, $file_name),
            default => self::send_file_url($this->send_url, $real_path, $mime_type, $file_name),
        };
    }

    public function md5($md5): AossCompleteRet
    {
        return self::check_file_complete($this->remote_url . $this->send_path . "/md5" . $this->send_token . $this->token, $md5);
    }

    public static function send_file_url($send_url, $real_path, $mime_type, $file_name): AossSimpleRet
    {
        $response = self::curl_send_file($real_path, $mime_type, $file_name, $send_url);
        return new AossSimpleRet($response);
    }

    public static function send_file_complete($send_url, $real_path, $mime_type, $file_name): AossCompleteRet
    {
        $response = self::curl_send_file($real_path, $mime_type, $file_name, $send_url);
        return new AossCompleteRet($response);
    }

    public static function check_file_complete($send_url, $md5): AossCompleteRet
    {
        $postData = [
            'md5' => $md5
        ];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $send_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        $response = curl_exec($ch);
        return new AossCompleteRet($response);
    }
}