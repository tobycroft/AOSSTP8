<?php

namespace app\v1\image\controller;

use app\v1\file\action\OssSelectionAction;
use app\v1\oss\model\OssModel;
use BaseController\CommonController;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Logo\Logo;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Ret;
use think\Request;

class qr extends CommonController
{


    public mixed $token;
    public mixed $proc;

    public function initialize()
    {
        set_time_limit(0);
        parent::initialize();
        $this->token = input('get.token');
        if (!$this->token) {
            \Ret::Fail(401, null, 'token');
        }
        $this->proc = OssModel::api_find_token($this->token);
        if (!$this->proc) {
            Ret::Fail(401, null, '项目不可用');
        }
        $this->proc = OssSelectionAction::App_find_byProc($this->proc);
    }

    public function png(Request $request)
    {
        if (!$request->has("data")) {
            \Ret::Fail(400, null, 'data');
        }
        $json = input('data');
        echo base64_encode($this->qr_png($json));
    }

    public function qr_png($data)
    {
        echo $this->qr($data);
        \think\facade\Response::contentType('image/png')->send();

    }

    public function qr($data)
    {
        $qrCode = new QrCode(
            data: $data,
            errorCorrectionLevel: ErrorCorrectionLevel::Low,
            size: 300,
            margin: 10,
            foregroundColor: new Color(0, 0, 0),
            backgroundColor: new Color(200, 200, 200)
        );

        $writer = new PngWriter();
        $result = $writer->write($qrCode);

        return $result->getString();
    }

    public function base64(Request $request)
    {
        if (!$request->has("data")) {
            \Ret::Fail(400, null, 'data');
        }
        $json = input("data");
        echo base64_encode($this->qr($json));
    }

    public function logo(Request $request)
    {
        if (!$request->has("data")) {
            \Ret::Fail(400, null, 'data');
        }
        if (!$request->has("url")) {
            \Ret::Fail(400, null, "url");
        }
        $json = input("data");
        $url = input("url");

        $tmpFile = $this->fetchRemoteLogo($url);
        try {
            $qrCode = new QrCode(
                data: $json,
                errorCorrectionLevel: ErrorCorrectionLevel::High,
                size: 400,
                margin: 15,
                foregroundColor: new Color(0, 0, 0),
                backgroundColor: new Color(255, 255, 255)
            );

            $logo = new Logo(
                path: $tmpFile,
                resizeToWidth: 150,
                resizeToHeight: 150
            );

            $writer = new PngWriter();
            $result = $writer->write($qrCode, $logo);

            header('Content-type: image/png');
            echo $result->getString();
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * 下载 logo 到临时文件。
     *
     * 仅允许 http/https，且目标必须是公网地址：解析出的 IP 全部落在私有/保留网段时拒绝，
     * 并用 CURLOPT_RESOLVE 固定解析结果，避免 DNS 重绑定绕过。限制 2MB、禁止跳转。
     */
    private function fetchRemoteLogo(string $url): string
    {
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
            Ret::Fail(400, null, 'url 仅支持 http/https');
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            Ret::Fail(400, null, 'url 无效');
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            $ips = gethostbynamel($host) ?: [];
        }
        if (!$ips) {
            Ret::Fail(400, null, 'logo 域名解析失败');
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                Ret::Fail(400, null, 'logo 地址不允许访问内网');
            }
        }

        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        $port = (int)(parse_url($url, PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80));

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $ips[0]],
            CURLOPT_USERAGENT => 'AOSS-QR/1.0',
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($body === false || $status !== 200) {
            Ret::Fail(400, null, 'logo 下载失败');
        }
        if (strlen($body) > 2 * 1024 * 1024) {
            Ret::Fail(400, null, 'logo 文件过大');
        }
        if (!preg_match('#^image/(png|jpe?g|gif|webp)#i', $contentType)) {
            Ret::Fail(400, null, 'logo 必须是图片');
        }
        $info = @getimagesizefromstring($body);
        if (!$info || empty($info['mime']) || !str_starts_with((string)$info['mime'], 'image/')) {
            Ret::Fail(400, null, 'logo 必须是图片');
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'qrlogo_');
        if ($tmpFile === false || file_put_contents($tmpFile, $body) === false) {
            Ret::Fail(500, null, 'logo 临时文件写入失败');
        }

        return $tmpFile;
    }

}
