<?php

namespace app\index\controller;

use app\index\model\ImageBedModel;
use app\index\utils\UserAuth;
use app\v1\oss\model\OssModel;
use BaseController\CommonController;
use Ret;
use think\facade\View;
use think\Request;

class Image extends CommonController
{
    private const OSS_TOKEN = 'imagebed';
    private const DEFAULT_URL = 'https://image.tuuz.cc:433';
    private const ALLOW_EXT = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
    private const MAX_SIZE = 10485760;

    /**
     * 图床图片访问域名（取 ao_oss 中 imagebed 项目的 url，缺失时降级到默认图片域）
     */
    private function getBaseUrl(): string
    {
        $proc = OssModel::api_find_token(self::OSS_TOKEN);
        return rtrim($proc['url'] ?? self::DEFAULT_URL, '/');
    }

    public function index(Request $request)
    {
        $user = UserAuth::requireLogin();
        if ($request->isGet()) {
            return $this->page($user);
        }
        return $this->upload($request, $user);
    }

    private function page(array $user)
    {
        $page = max(1, intval(input('page')));
        $list = (new ImageBedModel())->api_list_by_uid(intval($user['id']), $page, 24);

        View::assign([
            'title' => '图床',
            'list' => $list,
            'nickname' => $user['nickname'] ?? '',
        ]);

        return response(View::fetch('image/index'));
    }

    private function upload(Request $request, array $user)
    {
        $file = $request->file('file');
        if (!$file) {
            Ret::Fail(400, null, '请上传图片文件');
        }

        $ext = strtolower($file->getOriginalExtension());
        if (!in_array($ext, self::ALLOW_EXT, true)) {
            Ret::Fail(403, null, '仅支持 ' . implode('/', self::ALLOW_EXT) . ' 格式');
        }

        $mime = $file->getOriginalMime();
        if ($mime === 'text/x-php' || $mime === 'text/html') {
            Ret::Fail(403, null, '禁止上传非法文件');
        }

        if ($file->getSize() > self::MAX_SIZE) {
            Ret::Fail(400, null, '图片不能超过 10MB');
        }

        $uid = intval($user['id']);
        $md5 = $file->md5();

        $exists = ImageBedModel::where('uid', $uid)->where('md5', $md5)->find();
        if ($exists) {
            Ret::Success(0, $exists->toArray(), '上传成功');
        }

        $sav_path = 'imagebed' . DIRECTORY_SEPARATOR . $uid;
        $info = $file->move('./upload' . DIRECTORY_SEPARATOR . $sav_path, $md5 . '.' . $ext);
        if (!$info) {
            Ret::Fail(300, null, '文件保存失败');
        }

        $relPath = $sav_path . '/' . $info->getFilename();
        $relPath = str_replace('\\', '/', $relPath);

        $width = 0;
        $height = 0;
        $imageSize = @getimagesize($info->getPathname());
        if ($imageSize) {
            $width = intval($imageSize[0]);
            $height = intval($imageSize[1]);
        }

        $data = [
            'uid' => $uid,
            'name' => $file->getOriginalName(),
            'path' => $relPath,
            'url' => $this->getBaseUrl() . '/' . $relPath,
            'mime' => $mime,
            'size' => $info->getSize(),
            'ext' => $ext,
            'md5' => $md5,
            'width' => $width,
            'height' => $height,
            'ip' => request()->ip(),
        ];

        ImageBedModel::create($data);
        Ret::Success(0, $data, '上传成功');
    }

    public function delete()
    {
        $user = UserAuth::requireLogin();
        $id = intval(input('id'));
        if ($id <= 0) {
            Ret::Fail(400, null, '参数错误');
        }

        $row = ImageBedModel::where('id', $id)->where('uid', intval($user['id']))->find();
        if (empty($row)) {
            Ret::Fail(404, null, '图片不存在');
        }

        $full = './upload/' . $row['path'];
        if (is_file($full)) {
            @unlink($full);
        }
        $row->delete();

        Ret::Success(0, [], '已删除');
    }
}