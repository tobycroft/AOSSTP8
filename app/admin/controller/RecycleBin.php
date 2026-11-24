<?php

namespace app\admin\controller;

use app\admin\service\AttachmentFileService;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;
use Ret;
use think\facade\Db;

/**
 * 附件回收站 V3：只看 V3 软删附件，支持恢复与彻底删除
 *
 * V3 附件判定与 AttachmentFileService 保持一致：tag='v3' 或 token 以 v3bucket: 开头。
 * 列表 SQL、恢复/彻底删除均强制 scope='v3'，避免误操作 V1/V2 附件。
 * 彻底删除（purge）会真正删除 upload/recycle/ 下的物理文件并清除 DB 行，不可恢复。
 */
class RecycleBin extends CommonController
{
    /** 允许 inline 预览的光栅图片：扩展名 => [Content-Type, getimagesize 类型] */
    private const RASTER_TYPES = [
        'jpg' => ['image/jpeg', IMAGETYPE_JPEG],
        'jpeg' => ['image/jpeg', IMAGETYPE_JPEG],
        'png' => ['image/png', IMAGETYPE_PNG],
        'gif' => ['image/gif', IMAGETYPE_GIF],
        'bmp' => ['image/bmp', IMAGETYPE_BMP],
        'webp' => ['image/webp', IMAGETYPE_WEBP],
        'ico' => ['image/x-icon', IMAGETYPE_ICO],
    ];

    public function initialize()
    {
        parent::initialize();
        AdminAuth::requireLogin();
    }

    public function index()
    {
        $method = request()->method();
        if ($method === 'GET') {
            return $this->page();
        }
        if ($method === 'POST' && request()->post('batch_restore')) {
            return $this->batchRestore();
        }
        if ($method === 'POST' && request()->post('batch_purge')) {
            return $this->batchPurge();
        }
        if ($method === 'DELETE') {
            return $this->purge();
        }
        Ret::Fail(405, null, '不支持的请求方法');
    }

    /**
     * 回收站附件读取：只读 upload/recycle/ 下的本地留底文件
     *
     * 回收站里的文件实体已在软删时搬到 recycle/YYYYMMDD/（OSS 附件也已下载留底），
     * 原 path 不再有文件，所以不能复用 V3 附件列表的读取接口，必须按 recycle_path 取。
     * 安全策略与 V3 附件一致：仅「扩展名 + 真实内容都是光栅图片」才 inline，
     * 其余一律 attachment 下载；SVG/HTML/PDF 等永不内联，避免后台脚本执行。
     */
    public function file()
    {
        $id = input('get.id', 0, 'intval');
        $download = input('get.download', 0, 'intval') === 1;
        $resolved = $this->resolveFile($id, $download);
        if (isset($resolved['error'])) {
            $this->abortFile($resolved['status'], $resolved['error']);
        }

        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: private, max-age=0, no-store');

        header('Content-Type: ' . $resolved['content_type']);
        header(
            'Content-Disposition: ' . ($resolved['inline'] ? 'inline' : 'attachment')
            . '; filename="' . str_replace('"', '', $resolved['fallback_name']) . '"'
            . "; filename*=UTF-8''" . rawurlencode($resolved['name'])
        );
        header('Content-Length: ' . (string)filesize($resolved['full']));

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        readfile($resolved['full']);
        exit;
    }

    /**
     * 解析并校验回收站读取请求：返回输出参数或 ['status'=>, 'error'=>]
     *
     * 抽成独立方法便于验证所有安全分支（V3 限定、路径穿越、真实图片类型、
     * inline/attachment 决策），file() 只负责按结果输出 header 与文件内容。
     *
     * @return array{status:int,error:string}|array{full:string,inline:bool,content_type:string,name:string,fallback_name:string}
     */
    private function resolveFile(int $id, bool $download): array
    {
        $fail = static fn(int $status, string $msg): array => ['status' => $status, 'error' => $msg];
        if ($id <= 0) {
            return $fail(400, '参数错误');
        }

        $row = Db::name('ao_attachment')->where('id', $id)->find();
        if (empty($row)) {
            return $fail(404, '附件不存在');
        }
        // 只允许 V3 且已软删的附件（与列表口径一致）
        $token = (string)($row['token'] ?? '');
        $isV3 = (string)($row['tag'] ?? '') === 'v3' || str_starts_with($token, 'v3bucket:');
        if (!$isV3 || (int)($row['is_deleted'] ?? 0) !== 1) {
            return $fail(404, '仅支持查看 V3 回收站附件');
        }

        $recyclePath = str_replace('\\', '/', (string)($row['recycle_path'] ?? ''));
        if (!$this->isRecycleRelativePath($recyclePath)) {
            return $fail(404, '回收站路径不合法');
        }

        $root = realpath(public_path() . 'upload');
        if ($root === false) {
            return $fail(404, 'upload 目录不存在');
        }
        $recycleRoot = $root . DIRECTORY_SEPARATOR . AttachmentFileService::RECYCLE_DIR;
        $full = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $recyclePath));
        if ($full === false || !is_file($full) || !str_starts_with($full, $recycleRoot . DIRECTORY_SEPARATOR)) {
            return $fail(404, '回收站文件不存在');
        }

        $ext = strtolower((string)($row['ext'] ?? ''));
        $raster = self::RASTER_TYPES[$ext] ?? null;
        $inline = !$download && $raster !== null && $this->isRealRaster($full, $raster[1]);

        $name = (string)($row['name'] ?? '');
        if ($name === '') {
            $name = basename($full);
        }
        $name = preg_replace('/[^\w.\-]+/u', '_', $name) ?: 'download';
        $fallback = preg_replace('/[^\x20-\x7e]/', '_', $name) ?: 'download';

        return [
            'full' => $full,
            'inline' => $inline,
            'content_type' => $inline ? $raster[0] : 'application/octet-stream',
            'name' => $name,
            'fallback_name' => $fallback,
        ];
    }

    /** recycle_path 必须是 recycle/ 开头的相对路径，且不含空段 / . / .. */
    private function isRecycleRelativePath(string $path): bool
    {
        if (!str_starts_with($path, AttachmentFileService::RECYCLE_DIR . '/') || str_contains($path, "\0")) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }
        return true;
    }

    /** 用 getimagesize 校验文件真实内容与扩展名一致 */
    private function isRealRaster(string $full, int $expectedType): bool
    {
        $info = @getimagesize($full);
        return is_array($info) && (int)($info[2] ?? 0) === $expectedType;
    }

    /** 直接输出纯文本错误，避免图片请求返回 JSON/HTML */
    private function abortFile(int $status, string $message): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo $message;
        exit;
    }

    private function page()
    {
        $page = input('get.page', 1, 'intval');
        // 两种排版：list 列表（每页 24）；grid 平铺卡片（每页 60，单页展示更多）
        $view = (string)input('get.view', 'list');
        if (!in_array($view, ['list', 'grid'], true)) {
            $view = 'list';
        }
        $limit = $view === 'grid' ? 60 : 24;
        $keyword = trim((string)input('get.keyword', ''));

        // 只看 V3：tag='v3' 或 token 以 v3bucket: 开头（v3 默认 Bucket 场景）
        $query = Db::name('ao_attachment')->where('is_deleted', 1)
            ->where(function ($q) {
                $q->where('tag', 'v3')->whereOr('token', 'like', 'v3bucket:%');
            });
        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->whereOr('md5', 'like', $like)
                    ->whereOr('path', 'like', $like)
                    ->whereOr('recycle_path', 'like', $like);
            });
        }
        $list = $query->order('deleted_at', 'desc')->paginate($limit, false, ['page' => $page])->toArray();

        $items = [];
        foreach ($list['data'] as $row) {
            $ext = strtolower((string)($row['ext'] ?? ''));
            $id = (int)$row['id'];
            $proxyUrl = '/admin/recycle_bin/file?id=' . $id;
            $isRaster = isset(self::RASTER_TYPES[$ext]);
            $items[] = [
                'id' => $id,
                'name' => (string)($row['name'] ?? ''),
                'path' => (string)($row['path'] ?? ''),
                'recycle_path' => (string)($row['recycle_path'] ?? ''),
                'deleted_at' => (string)($row['deleted_at'] ?? ''),
                'size' => $this->formatSize((int)($row['size'] ?? 0)),
                'ext' => $ext,
                'md5' => (string)($row['md5'] ?? ''),
                'token' => (string)($row['token'] ?? ''),
                // 回收站文件只能从 recycle_path 读，统一走本站受控接口
                'is_raster' => $isRaster,
                'thumb_url' => $isRaster ? $proxyUrl : '',
                'open_url' => $proxyUrl,
                'download_url' => $proxyUrl . '&download=1',
            ];
        }

        $extraParams = [];
        if ($keyword !== '') {
            $extraParams['keyword'] = $keyword;
        }
        // 排版方式随分页一起携带，翻页后不会丢
        $extraParams['view'] = $view;

        // 列表 / 平铺切换地址：保留关键字，回到第 1 页
        $switchParams = $extraParams;
        $switchParams['page'] = 1;
        $switchParams['view'] = 'list';
        $listUrl = '/admin/recycle_bin?' . http_build_query($switchParams);
        $switchParams['view'] = 'grid';
        $gridUrl = '/admin/recycle_bin?' . http_build_query($switchParams);

        $pagination = Layout::pagination(
            (int)$list['current_page'],
            max(1, (int)ceil($list['total'] / $limit)),
            '/admin/recycle_bin',
            $extraParams,
            $limit
        );

        return $this->renderPage('recycle_bin/index', [
            'list' => $items,
            'keyword' => $keyword,
            'view' => $view,
            'listUrl' => $listUrl,
            'gridUrl' => $gridUrl,
            'pagination' => $pagination,
        ], '附件回收站 V3', 'v3_storage', 'recycle_bin');
    }

    private function purge()
    {
        $id = intval(request()->delete('id'));
        if ($id <= 0) {
            Ret::Fail(400, null, '缺少参数[id]');
        }
        $this->respond(AttachmentFileService::purge([$id], 'v3'), '彻底删除成功');
    }

    private function batchPurge()
    {
        $ids = $this->readIds();
        $this->respond(AttachmentFileService::purge($ids, 'v3'), '批量彻底删除成功');
    }

    private function batchRestore()
    {
        $ids = $this->readIds();
        $this->respond(AttachmentFileService::restore($ids, 'v3'), '批量恢复成功');
    }

    private function readIds(): array
    {
        $raw = trim((string)request()->post('ids', ''));
        return array_values(array_filter(array_map('intval', explode(',', $raw))));
    }

    private function respond(array $result, string $successEcho): void
    {
        if (!empty($result['errors']) && $result['deleted'] === 0) {
            Ret::Fail(500, $result, '操作失败：' . implode('；', $result['errors']));
        }
        $echo = $successEcho . '，共' . $result['deleted'] . '条';
        if (!empty($result['errors'])) {
            $echo .= '；失败：' . implode('；', $result['errors']);
        }
        if (!empty($result['warnings'])) {
            $echo .= '；注意：' . implode('；', $result['warnings']);
        }
        Ret::Success(0, $result, $echo);
    }

    private function formatSize(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }
}
