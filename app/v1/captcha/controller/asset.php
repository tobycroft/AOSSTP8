<?php

namespace app\v1\captcha\controller;

use app\v2\project\model\ProjectModel;
use BaseController\CommonController;
use Input;
use Ret;
use think\facade\Filesystem;

class asset extends CommonController
{

    public $token;
    public mixed $proc;

    public function initialize()
    {
        parent::initialize();
        if (!$this->token) {
            $this->token = Input::Combi('token');
        }
        $this->proc = (new ProjectModel())->api_find_token($this->token);
        if (!$this->proc) {
            Ret::Fail(401, null, '项目不可用');
        }
    }

    /**
     * Template 模式：返回完整的 HTML 模板，token 已注入，打开即可使用
     * GET /v1/captcha/asset/html?token=xxx
     */
    public function html()
    {
        // 使用相对路径，让浏览器自动拼接当前访问的 scheme+host+port
        $apiPath = $this->getApiPath();
        $html = $this->generateHtmlTemplate($apiPath);
        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store'
        ]);
    }

    /**
     * Template 模式：返回 JSON 配置，供前端 SDK 初始化使用
     * GET /v1/captcha/asset/config?token=xxx
     */
    public function config()
    {
        // 使用相对路径，让前端自动拼接当前访问的 scheme+host+port
        $apiPath = $this->getApiPath();
        $config = [
            'token' => $this->token,
            'api_path' => $apiPath,
            'create_url' => $apiPath . '/slide/create',
            'check_url' => $apiPath . '/slide/check',
            'asset_url' => $apiPath . '/asset',
            'assets' => [
                'js' => $apiPath . '/asset/get?file=slide.js&token=' . $this->token,
                'css' => $apiPath . '/asset/get?file=slide.css&token=' . $this->token,
                'html' => $apiPath . '/asset/html?token=' . $this->token,
            ],
            'captcha' => [
                'type' => 'slide',
                'bg_width' => 300,
                'bg_height' => 150,
                'block_size' => 40,
                'tolerance' => 4,
            ],
            'timestamp' => time()
        ];
        Ret::Success(0, $config);
    }

    /**
     * 获取静态文件
     * GET /v1/captcha/asset/get?token=xxx&file=slide.js
     */
    public function get()
    {
        $file = Input::Get('file', true);
        $allowedFiles = [
            'slide.js',
            'slide.css',
            'slide.html',
            'README.md'
        ];

        if (!in_array($file, $allowedFiles)) {
            Ret::Fail(403, null, '文件不存在');
        }

        $filePath = public_path() . 'static/captcha/' . $file;
        if (!file_exists($filePath)) {
            Ret::Fail(404, null, '文件不存在');
        }

        $content = file_get_contents($filePath);
        $mimeType = $this->getMimeType($file);

        return response($content, 200, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'public, max-age=86400'
        ]);
    }

    /**
     * 获取 API 的相对路径（不含 scheme/host/port）
     * 让浏览器自动拼接当前访问的完整地址，适配防火墙转发场景
     */
    private function getApiPath()
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/v1/captcha/asset/html';
        $path = parse_url($requestUri, PHP_URL_PATH);
        $apiPath = preg_replace('#/asset/.*$#', '', $path);
        return rtrim($apiPath, '/');
    }

    /**
     * 生成完整的 HTML 模板
     */
    private function generateHtmlTemplate($apiPath)
    {
        $token = htmlspecialchars($this->token, ENT_QUOTES, 'UTF-8');
        // 使用相对路径，浏览器会自动拼接当前访问的 scheme+host+port
        $jsUrl = $apiPath . '/asset/get?file=slide.js&token=' . $token;
        $cssUrl = $apiPath . '/asset/get?file=slide.css&token=' . $token;

        return '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>滑动验证码</title>
    <link rel="stylesheet" href="' . htmlspecialchars($cssUrl, ENT_QUOTES, 'UTF-8') . '">
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f5f5f5;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
        }
        .captcha-wrapper {
            text-align: center;
        }
        .captcha-container {
            position: relative;
            width: 300px;
            height: 190px;
            border: 1px solid #ccc;
            background: white;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .captcha-bg {
            width: 300px;
            height: 150px;
            display: block;
            position: absolute;
            top: 0;
            left: 0;
        }
        .captcha-block {
            position: absolute;
            left: 0;
            top: 0;
            cursor: grab;
            z-index: 10;
        }
        .captcha-block:active {
            cursor: grabbing;
        }
        .captcha-slider {
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 40px;
            background: #f5f5f5;
            border-top: 1px solid #eee;
        }
        .slider-handle {
            width: 40px;
            height: 100%;
            background: #409eff;
            color: white;
            text-align: center;
            line-height: 40px;
            cursor: grab;
            position: absolute;
            font-size: 16px;
            border-radius: 2px;
        }
        .slider-handle:active {
            cursor: grabbing;
        }
        .status {
            margin-top: 20px;
            text-align: center;
            color: #666;
            height: 24px;
            transition: all 0.3s ease;
        }
        .success {
            color: #67c23a;
            font-weight: bold;
        }
        .error {
            color: #f56c6c;
            font-weight: bold;
        }
        .reloading {
            color: #e6a23c;
            font-weight: bold;
        }
        .reload-btn {
            margin-top: 10px;
            padding: 8px 16px;
            background: #409eff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            transition: background 0.3s ease;
        }
        .reload-btn:hover {
            background: #66b1ff;
        }
        .reload-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
        .loading-indicator {
            display: inline-block;
            margin-left: 8px;
        }
        .loading-spinner {
            display: inline-block;
            width: 12px;
            height: 12px;
            border: 2px solid #e6a23c;
            border-top-color: transparent;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="captcha-wrapper">
        <div class="captcha-container">
            <img class="captcha-bg" src="" alt="验证码背景">
            <img class="captcha-block" src="" alt="验证码块" style="display: none;">
            <div class="captcha-slider">
                <div class="slider-handle">👉</div>
            </div>
        </div>
        <div class="status">请拖动滑块完成拼图</div>
        <button class="reload-btn" style="display: none;">点击重新加载</button>
    </div>
    <script src="' . htmlspecialchars($jsUrl, ENT_QUOTES, 'UTF-8') . '"></script>
    <script>
        const statusEl = document.querySelector(".status");
        const reloadBtn = document.querySelector(".reload-btn");

        initSlideCaptacle({
            token: "' . $token . '",
            apiUrl: "' . htmlspecialchars($apiPath, ENT_QUOTES, 'UTF-8') . '",
            onSuccess: function() {
                statusEl.textContent = "✅ 验证成功！";
                statusEl.className = "status success";
                reloadBtn.style.display = "inline-block";
                reloadBtn.disabled = false;
            },
            onError: function(msg) {
                statusEl.className = "status error";
                statusEl.innerHTML = "❌ " + (msg || "验证失败") + "，正在自动重新获取... <span class=\"loading-indicator\"><span class=\"loading-spinner\"></span></span>";
                reloadBtn.disabled = true;
                reloadBtn.style.display = "inline-block";

                setTimeout(() => {
                    if (statusEl.className.includes("error")) {
                        statusEl.className = "status reloading";
                        statusEl.innerHTML = "🔄 正在获取新验证码... <span class=\"loading-indicator\"><span class=\"loading-spinner\"></span></span>";
                    }
                }, 1500);

                setTimeout(() => {
                    statusEl.className = "status";
                    statusEl.textContent = "请拖动滑块完成拼图";
                    reloadBtn.disabled = false;
                }, 2000);
            }
        });
    </script>
</body>
</html>';
    }

    private function getMimeType($file)
    {
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        $mimeTypes = [
            'js' => 'application/javascript',
            'css' => 'text/css',
            'html' => 'text/html',
            'md' => 'text/markdown',
        ];
        return $mimeTypes[$ext] ?? 'application/octet-stream';
    }
}