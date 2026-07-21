<?php

namespace app\v1\captcha\controller;

use app\v2\project\model\ProjectModel;
use BaseController\CommonController;
use Input;
use Ret;

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
     * 返回完整的 HTML 模板页面，token 已注入，打开即可使用
     * GET /v1/captcha/asset/html?token=xxx&type=slide
     * GET /v1/captcha/asset/html?token=xxx&type=click
     */
    public function html()
    {
        $type = Input::Get('type', true);
        if (!in_array($type, ['slide', 'click'])) {
            $type = 'slide';
        }

        $apiPath = $this->getApiPath();
        $token = htmlspecialchars($this->token, ENT_QUOTES, 'UTF-8');
        $jsUrl = $apiPath . '/asset/get?file=' . $type . '.js&token=' . $token;
        $cssUrl = $apiPath . '/asset/get?file=' . $type . '.css&token=' . $token;

        $viewFile = app_path() . 'v1/captcha/view/asset/' . $type . '.html';
        if (!file_exists($viewFile)) {
            Ret::Fail(404, null, '模板不存在');
        }

        ob_start();
        include $viewFile;
        $content = ob_get_clean();

        return response($content, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * 返回 JSON 配置，供前端 SDK 初始化使用
     * GET /v1/captcha/asset/config?token=xxx&type=slide
     * GET /v1/captcha/asset/config?token=xxx&type=click
     */
    public function config()
    {
        $type = Input::Get('type', true);
        if (!in_array($type, ['slide', 'click'])) {
            $type = 'slide';
        }

        $apiPath = $this->getApiPath();
        $config = [
            'token' => $this->token,
            'type' => $type,
            'api_path' => $apiPath,
            'create_url' => $apiPath . '/' . $type . '/create',
            'check_url' => $apiPath . '/' . $type . '/check',
            'asset_url' => $apiPath . '/asset',
            'assets' => [
                'js' => $apiPath . '/asset/get?file=' . $type . '.js&token=' . $this->token,
                'css' => $apiPath . '/asset/get?file=' . $type . '.css&token=' . $this->token,
                'html' => $apiPath . '/asset/html?type=' . $type . '&token=' . $this->token,
            ],
            'captcha' => $type === 'slide' ? [
                'type' => 'slide',
                'bg_width' => 300,
                'bg_height' => 150,
                'block_size' => 40,
                'tolerance' => 4,
            ] : [
                'type' => 'click',
                'bg_width' => 300,
                'bg_height' => 200,
                'font_size' => 28,
                'tolerance' => 30,
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
            'click.js',
            'click.css',
            'click.html',
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