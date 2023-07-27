<?php

use think\Exception;

class Net
{
    public static function PostJson(string $base_url, array $query = [], array $postData = [])
    {
        $send_url = $base_url;
        if (!empty($query)) {
            $send_url .= '?' . http_build_query($query);
        }
        $headers = array('Content-type: application/json;charset=UTF-8', 'Accept: application/json', 'Cache-Control: no-cache', 'Pragma: no-cache');
        if (!empty($postData)) {
            $postData = json_encode($postData, 320);
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $send_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);
        if ($response === false) {
            if (curl_errno($ch) == CURLE_OPERATION_TIMEDOUT) {
                throw new Exception('PostJson超时');
            }
        }
        curl_close($ch);
        return $response;
    }

    public static function PostForm(string $base_url, array $query = [], array $postData = [])
    {
        $send_url = $base_url;
        if (!empty($query)) {
            $send_url .= '?' . http_build_query($query);
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $send_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        $response = curl_exec($ch);
        if ($response === false) {
            if (curl_errno($ch) == CURLE_OPERATION_TIMEDOUT) {
                throw new Exception('PostJson超时');
            }
        }
        curl_close($ch);
        return $response;
    }

    /**
     * @send("文件地址","文件类型","文件名称")
     * @param $real_path
     * @param $mime_type
     * @param $file_name
     * @param $send_url
     * @return bool|string
     */
    public static function PostFile($send_url, $real_path): string|bool
    {
        $postData = [
            'file' => new CURLFile(realpath($real_path))
        ];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $send_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        $response = curl_exec($ch);
        curl_close($ch);
        return $response;
    }

    public function PostBinary($url, $data = array())
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_URL, $url);//上传类
        curl_setopt($ch, CURLOPT_TIMEOUT, 40);
        $result = curl_exec($ch);
        if (0 != curl_errno($ch)) {
            $result['error'] = "Error:\n" . curl_error($ch);

        }
        $httpCodes = curl_getinfo($ch);
        curl_close($ch);
        return $result;
    }


}