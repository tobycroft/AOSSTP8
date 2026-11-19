<?php

namespace app\index\controller;

use app\index\utils\UserAuth;
use app\v1\file\action\OssSelectionAction;
use app\v1\file\controller\index as FileIndex;
use app\v1\file\model\AttachmentModel;
use app\v1\oss\model\OssModel;
use Ret;
use think\facade\View;
use think\Request;

/**
 * 门户图床：基于 ao_attachment 实现，复用 v1 文件上传逻辑。
 * 通过 ao_oss 中 token=imagebed 项目的 ext/size/url 控制图片类型、大小与访问域名，
 * 并以 tag 列存储门户用户ID，实现按用户隔离与秒传去重。
 */
class Image extends FileIndex
{
    private const OSS_TOKEN = 'imagebed';
    private const DEFAULT_URL = 'https://image.tuuz.cc:433';

    protected string $upload_tag = '';
    protected array $login_user = [];

    public function initialize()
    {
        header("Access-Control-Allow-Origin: *", true);
        header("Access-Control-Max-Age: 86400", true);
        header("Access-Control-Allow-Credentials: true", true);
        header("Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS", true);
        header("Access-Control-Allow-Headers: *", true);

        $user = UserAuth::requireLogin();
        $this->login_user = $user;
        $this->token = self::OSS_TOKEN;
        $this->upload_tag = (string)$user['id'];

        $proc = OssModel::api_find_token($this->token);
        $this->proc = OssSelectionAction::App_find_byProc($proc);
        if (empty($this->proc) || ($this->proc['type'] ?? '') === 'none') {
            Ret::Fail(400, null, '图床存储未配置');
        }
    }

    private function baseUrl(): string
    {
        return rtrim($this->proc['url'] ?? self::DEFAULT_URL, '/');
    }

    public function index(Request $request)
    {
        if ($request->isGet()) {
            return $this->page();
        }
        // POST：复用 v1 上传逻辑（后缀/大小校验、秒传、写入 ao_attachment 均已内置）
        return $this->up($request);
    }

    private function page()
    {
        $page = max(1, intval(input('page')));
        $list = AttachmentModel::where('token', self::OSS_TOKEN)
            ->where('tag', $this->upload_tag)
            ->order('id', 'desc')
            ->paginate(24, false, ['page' => $page])->toArray();

        $base = $this->baseUrl();
        foreach ($list['data'] as &$row) {
            $row['url'] = $base . '/' . $row['path'];
        }
        unset($row);

        View::assign([
            'title' => '图床',
            'list' => $list,
            'nickname' => $this->login_user['nickname'] ?? '',
        ]);

        return response(View::fetch('image/index'));
    }

    public function delete()
    {
        $id = intval(input('id'));
        if ($id <= 0) {
            Ret::Fail(400, null, '参数错误');
        }

        $row = AttachmentModel::where('id', $id)
            ->where('token', self::OSS_TOKEN)
            ->where('tag', $this->upload_tag)
            ->find();
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