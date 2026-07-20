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

    public function download()
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

        return download($filePath, $file);
    }

    public function package()
    {
        $zipPath = sys_get_temp_dir() . '/captcha-package-' . time() . '.zip';
        $zip = new \ZipArchive();

        if ($zip->open($zipPath, \ZipArchive::CREATE) !== true) {
            Ret::Fail(500, null, '创建压缩包失败');
        }

        $files = [
            'slide.js',
            'slide.css',
            'slide.html',
            'README.md'
        ];

        foreach ($files as $file) {
            $filePath = public_path() . 'static/captcha/' . $file;
            if (file_exists($filePath)) {
                $zip->addFile($filePath, $file);
            }
        }

        $zip->close();

        return download($zipPath, 'captcha-package.zip');
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