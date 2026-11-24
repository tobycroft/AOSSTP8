<?php

namespace app\admin\controller;

use app\admin\service\AttachmentFileService;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use app\v3\storage\model\V3StorageProjectModel;
use BaseController\CommonController;
use Ret;
use think\facade\Db;

/**
 * 存储管理 V3 → 附件列表
 *
 * 只展示 V3 上传产生的附件（ao_attachment.tag = v3；token 为项目名称，
 * 历史数据兼容 v3bucket:{storage_id}），支持按项目/Bucket 筛选，并可叠加
 * 后缀、关键字过滤。一个项目可关联多个 Bucket，因此 Bucket 归属按
 * attachment.path 的前缀匹配关联 Bucket（不再按项目名唯一映射）。
 *
 * 安全说明：图片预览统一走本站的 file 接口，而不是直接把 upload/ 下的文件
 * 交给浏览器解析：
 *   - 只对光栅图片（jpg/jpeg/png/gif/bmp/webp/ico）允许 inline 展示；
 *   - 再用 getimagesize 校验文件真实类型，扩展名与实际内容不符一律降级为下载；
 *   - SVG / HTML / PDF 等可能携带脚本的类型永不 inline，强制 attachment 下载；
 *   - 所有响应带 nosniff、CSP sandbox、私有缓存等安全头。
 * 这样即使历史上已上传了恶意文件，也不会在后台页面里被执行。
 */
class V3Attachment extends CommonController
{
    /** 本站本地文件对外访问的图片站地址（Bucket 未配置 url 时兜底） */
    private const IMAGE_BASE_URL = 'https://image.tuuz.cc:433';

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
        if ($method === 'DELETE') {
            return $this->delete();
        }
        if ($method === 'POST' && request()->post('batch_delete')) {
            return $this->batchDelete();
        }
        if ($method !== 'GET') {
            Ret::Fail(405, null, '不支持的请求方法');
        }
        return $this->page();
    }

    /** 删除单条 V3 附件：DB 记录 + 本地文件 / OSS 对象 */
    private function delete()
    {
        $id = intval(request()->delete('id'));
        if ($id <= 0) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $row = Db::name('ao_attachment')->where('id', $id)->findOrEmpty();
        if (empty($row)) {
            Ret::Fail(404, null, '附件不存在');
        }

        $result = AttachmentFileService::delete([$id], 'v3');
        if (!empty($result['errors'])) {
            Ret::Fail(500, $result, '删除失败：' . implode('；', $result['errors']));
        }

        $echo = '删除成功';
        if (!empty($result['warnings'])) {
            $echo .= '（注意：' . implode('；', $result['warnings']) . '）';
        }
        Ret::Success(0, $result, $echo);
    }

    /** 批量删除 V3 附件 */
    private function batchDelete()
    {
        $ids = trim((string)input('post.ids', ''));
        $idArr = array_filter(array_map('intval', explode(',', $ids)));
        if (empty($idArr)) {
            Ret::Fail(400, null, '未选择任何附件');
        }

        $result = AttachmentFileService::delete($idArr, 'v3');
        if (!empty($result['errors']) && $result['deleted'] === 0) {
            Ret::Fail(500, $result, '批量删除失败：' . implode('；', $result['errors']));
        }

        $echo = '批量删除成功，共删除' . $result['deleted'] . '条';
        if (!empty($result['errors'])) {
            $echo .= '；失败：' . implode('；', $result['errors']);
        }
        if (!empty($result['warnings'])) {
            $echo .= '；注意：' . implode('；', $result['warnings']);
        }
        Ret::Success(0, $result, $echo);
    }

    private function page()
    {
        $page = input('get.page', 1, 'intval');
        // 两种排版：list 列表（每页 24）；grid 横排卡片（每页 60，单页展示更多）
        $view = (string)input('get.view', 'list');
        if (!in_array($view, ['list', 'grid'], true)) {
            $view = 'list';
        }
        $limit = $view === 'grid' ? 60 : 24;
        $projectId = input('get.project_id', 0, 'intval');
        $bucketId = input('get.bucket_id', 0, 'intval');
        $keyword = trim((string)input('get.keyword', ''));
        $ext = strtolower(trim((string)input('get.ext', '')));

        // 用查询构造器取数组：避开 Model::toArray() 在 PHP 8.6 下对字符串属性
        // 调用 is_subclass_of(..., false) 触发的弃用告警（与 V3 其它接口保持一致）。
        $buckets = Db::name('ao_v3_storage')->order('id', 'asc')->select()->toArray();
        $bucketMap = [];
        foreach ($buckets as $bucket) {
            $bucketMap[(int)$bucket['id']] = $bucket;
        }

        $projectNames = [];
        foreach (Db::name('ao_v3_project')->field('id, name')->select()->toArray() as $project) {
            $projectNames[(int)$project['id']] = (string)$project['name'];
        }

        // Bucket → 项目：新关联表优先，ao_v3_storage.project_id 仅作历史兜底
        $bucketProjectMap = [];
        foreach (Db::name('ao_v3_storage_project')->select()->toArray() as $binding) {
            $bucketProjectMap[(int)$binding['bucket_id']] = (int)$binding['project_id'];
        }
        foreach ($buckets as $bucket) {
            $bid = (int)$bucket['id'];
            if (!isset($bucketProjectMap[$bid])) {
                $bucketProjectMap[$bid] = (int)($bucket['project_id'] ?? 0);
            }
        }

        // token 现在是项目名称（如 stb / flamecloud）；tag=v3 用于与 V1/V2 隔离。
        // 一个项目可关联多个 Bucket，不能按项目名唯一映射，具体归属由 path 前缀判断。
        $projectNameIds = array_flip($projectNames);

        $query = Db::name('ao_attachment')
            ->where('is_deleted', 0)
            ->where(function ($q) {
                $q->where('tag', 'v3')->whereOr('token', 'like', 'v3bucket:%');
            });

        // 维度过滤：Bucket 优先。token 为项目名时无法区分同项目的多个 Bucket，
        // 按 Bucket 过滤需再叠加 path 前缀（历史 token=v3bucket:{id} 仍按 id 命中）。
        if ($bucketId > 0) {
            $bucket = $bucketMap[$bucketId] ?? null;
            $tokens = ['v3bucket:' . $bucketId];
            $prefixes = [];
            if ($bucket) {
                $pid = (int)($bucketProjectMap[$bucketId] ?? 0);
                $name = $projectNames[$pid] ?? '';
                if ($name !== '') {
                    $tokens[] = $name;
                }
                foreach ([(string)($bucket['path'] ?? ''), (string)($bucket['name'] ?? '')] as $raw) {
                    $prefix = trim($raw, '/');
                    if ($prefix !== '') {
                        $prefixes[] = $prefix . '/%';
                    }
                }
            }
            $query->where(function ($q) use ($tokens, $prefixes) {
                $q->whereIn('token', $tokens);
                foreach ($prefixes as $prefix) {
                    $q->whereOr('path', 'like', $prefix);
                }
            });
        } elseif ($projectId > 0) {
            $tokens = [];
            $name = $projectNames[$projectId] ?? '';
            if ($name !== '') {
                $tokens[] = $name;
            }
            foreach ($bucketProjectMap as $bid => $pid) {
                if ((int)$pid === $projectId) {
                    $tokens[] = 'v3bucket:' . (int)$bid;
                }
            }
            if (empty($tokens)) {
                $query->where('id', '<', 0);
            } else {
                $query->whereIn('token', array_values(array_unique($tokens)));
            }
        }

        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->whereOr('md5', 'like', $like)
                    ->whereOr('path', 'like', $like);
            });
        }
        if ($ext !== '') {
            $query->where('ext', $ext);
        }

        $list = $query->order('id', 'desc')->paginate($limit, false, ['page' => $page])->toArray();

        $items = [];
        foreach ($list['data'] as $row) {
            $items[] = $this->formatItem($row, $bucketMap, $bucketProjectMap, $projectNameIds, $projectNames);
        }

        // 可选的扩展名过滤项：仅取 V3 附件已有后缀
        $extOptions = Db::name('ao_attachment')
            ->where('is_deleted', 0)
            ->where(function ($q) {
                $q->where('tag', 'v3')->whereOr('token', 'like', 'v3bucket:%');
            })
            ->distinct(true)
            ->column('ext');

        $projectOptions = [];
        foreach ($projectNames as $id => $name) {
            $projectOptions[] = ['id' => $id, 'name' => $name];
        }
        $bucketOptions = [];
        foreach ($buckets as $bucket) {
            $bid = (int)$bucket['id'];
            $pid = (int)($bucketProjectMap[$bid] ?? 0);
            $bucketOptions[] = [
                'id' => $bid,
                'project_id' => $pid,
                'label' => (string)$bucket['name']
                    . ((string)($bucket['path'] ?? '') !== '' ? '（' . $bucket['path'] . '）' : ''),
            ];
        }

        $extraParams = [];
        if ($projectId > 0) $extraParams['project_id'] = $projectId;
        if ($bucketId > 0) $extraParams['bucket_id'] = $bucketId;
        if ($keyword !== '') $extraParams['keyword'] = $keyword;
        if ($ext !== '') $extraParams['ext'] = $ext;
        // 排版方式随分页/筛选一起携带，翻页后不会丢
        $extraParams['view'] = $view;

        // 列表 / 横排切换地址：保留当前筛选条件，回到第 1 页
        $switchParams = $extraParams;
        $switchParams['page'] = 1;
        $switchParams['view'] = 'list';
        $listUrl = '/admin/v3_attachment?' . http_build_query($switchParams);
        $switchParams['view'] = 'grid';
        $gridUrl = '/admin/v3_attachment?' . http_build_query($switchParams);

        $pagination = Layout::pagination(
            (int)$list['current_page'],
            max(1, (int)ceil($list['total'] / $limit)),
            '/admin/v3_attachment',
            $extraParams,
            $limit
        );

        return $this->renderPage('v3_attachment/index', [
            'list' => $items,
            'extOptions' => $extOptions,
            'projectOptions' => $projectOptions,
            'bucketOptions' => $bucketOptions,
            'projectId' => $projectId,
            'bucketId' => $bucketId,
            'keyword' => $keyword,
            'ext' => $ext,
            'view' => $view,
            'listUrl' => $listUrl,
            'gridUrl' => $gridUrl,
            'pagination' => $pagination,
        ], '附件列表 V3', 'v3_storage', 'v3_attachment');
    }

    /**
     * 组装列表行：解析所属 Bucket/项目，并给出安全的预览/下载地址
     */
    private function formatItem(array $row, array $bucketMap, array $bucketProjectMap, array $projectNameIds, array $projectNames): array
    {
        $id = (int)$row['id'];
        $ext = strtolower((string)($row['ext'] ?? ''));
        $path = str_replace('\\', '/', (string)($row['path'] ?? ''));
        $token = (string)($row['token'] ?? '');

        // token 为项目名时一个项目可关联多个 Bucket：用 path 前缀精确定位；
        // 历史 token=v3bucket:{id} 直接按 id 定位，Bucket 已删则从内存表兜底展示。
        $bucket = V3StorageProjectModel::api_match_by_path($token, $path);
        $bucketId = (int)($bucket['id'] ?? 0);
        if ($bucketId === 0 && preg_match('/^v3bucket:(\d+)$/', $token, $m) === 1) {
            $bucketId = (int)$m[1];
            $bucket = $bucketMap[$bucketId] ?? null;
        }
        if ($bucketId === 0) {
            $bucket = null;
        }
        $projectId = $bucketId > 0 ? (int)($bucketProjectMap[$bucketId] ?? 0) : 0;
        if ($projectId <= 0 && isset($projectNameIds[$token])) {
            $projectId = (int)$projectNameIds[$token];
        }
        if ($bucket) {
            $bucketName = (string)$bucket['name'];
        } elseif ($bucketId > 0) {
            $bucketName = '已删除 Bucket #' . $bucketId;
        } else {
            $bucketName = '已删除 Bucket';
        }

        // 对外图片地址：Bucket 配了 url 就用它；本地 Bucket 未配置时回退到图片站地址。
        // 拼接后形如 https://image.tuuz.cc:433/stb/20261121/xxx.jpg
        $imageBase = rtrim((string)($bucket['url'] ?? ''), '/');
        if ($imageBase === '' && (!$bucket || (string)$bucket['type'] === 'local')) {
            $imageBase = self::IMAGE_BASE_URL;
        }
        $publicUrl = ($imageBase !== '' && $this->isSafeRelativePath($path)) ? $imageBase . '/' . $path : '';
        $proxyUrl = '/admin/v3_attachment/file?id=' . $id;

        $isRaster = isset(self::RASTER_TYPES[$ext]);
        // 光栅图优先使用拼接后的图片站地址；没有可用地址时回退到后台受控代理
        $thumbUrl = $isRaster ? ($publicUrl !== '' ? $publicUrl : $proxyUrl) : '';
        $fallbackUrl = $isRaster && $publicUrl !== '' ? $proxyUrl : '';

        $width = (int)($row['width'] ?? 0);
        $height = (int)($row['height'] ?? 0);
        $duration = (int)($row['duration'] ?? 0);

        return [
            'id' => $id,
            'token' => (string)($row['token'] ?? ''),
            'tag' => (string)($row['tag'] ?? ''),
            'bucket_id' => $bucketId,
            'bucket_name' => $bucketName,
            'bucket_type' => (string)($bucket['type'] ?? ''),
            'project_id' => $projectId,
            'project_name' => $projectId > 0 ? ($projectNames[$projectId] ?? ('已删除项目(' . $projectId . ')')) : '-',
            'name' => (string)($row['name'] ?? ''),
            'path' => $path,
            'ext' => $ext,
            'mime' => (string)($row['mime'] ?? ''),
            'size' => $this->formatSize((int)($row['size'] ?? 0)),
            'size_bytes' => (int)($row['size'] ?? 0),
            'resolution' => ($width > 0 && $height > 0) ? ($width . ' × ' . $height) : '-',
            'duration' => $duration > 0 ? (string)($row['duration_str'] ?? '') : '-',
            'bitrate' => (float)($row['bitrate'] ?? 0),
            'md5' => (string)($row['md5'] ?? ''),
            'sha1' => (string)($row['sha1'] ?? ''),
            'ip' => (string)($row['ip'] ?? ''),
            'create_time' => (string)($row['create_time'] ?? '-'),
            'is_raster' => $isRaster,
            'thumb_url' => $thumbUrl,
            'fallback_url' => $fallbackUrl,
            // 对外文件地址（图片站/OSS 域名 + path），供展示与复制
            'public_url' => $publicUrl,
            // 查看：光栅图跳图片站原图；其余走后台受控接口（远程 Bucket 会 302 到 OSS）
            'open_url' => $isRaster && $publicUrl !== '' ? $publicUrl : $proxyUrl,
            // 下载：本地强制 attachment，远程直接使用 OSS 地址
            'download_url' => ($bucket && (string)$bucket['type'] === 'oss' && $publicUrl !== '')
                ? $publicUrl
                : $proxyUrl . '&download=1',
        ];
    }

    /**
     * 附件读取：本地文件受控输出，远程 OSS 直接跳转
     *
     * 默认只对「扩展名 + 真实内容都是光栅图片」的文件 inline 输出，
     * 其余一律 attachment 下载，避免 SVG/HTML 等文件在后台源里执行脚本。
     */
    public function file()
    {
        $id = input('get.id', 0, 'intval');
        $download = input('get.download', 0, 'intval') === 1;
        if ($id <= 0) {
            $this->abortFile(400, '参数错误');
        }

        $row = Db::name('ao_attachment')->where('id', $id)->find();
        if (empty($row)) {
            $this->abortFile(404, '附件不存在');
        }
        // 只允许读取 V3 附件：tag=v3；历史数据 token 以 v3bucket: 开头
        $token = (string)($row['token'] ?? '');
        $isV3 = (string)($row['tag'] ?? '') === 'v3' || str_starts_with($token, 'v3bucket:');
        if (!$isV3) {
            $this->abortFile(404, '仅支持查看 V3 附件');
        }

        $path = str_replace('\\', '/', (string)($row['path'] ?? ''));
        // token 为项目名时按 path 前缀定位 Bucket，避免多 Bucket 时用错 OSS 域名
        $bucket = V3StorageProjectModel::api_match_by_path($token, $path);

        if (($bucket['type'] ?? 'local') === 'oss') {
            $base = rtrim((string)($bucket['url'] ?? ''), '/');
            if ($base === '' || $path === '' || !$this->isSafeRelativePath($path)) {
                $this->abortFile(404, '远程附件地址不可用');
            }
            header('Location: ' . $base . '/' . $path);
            exit;
        }

        $root = realpath(public_path() . 'upload');
        if ($root === false || !$this->isSafeRelativePath($path)) {
            $this->abortFile(404, '附件路径不合法');
        }
        $full = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path));
        if ($full === false || !str_starts_with($full, $root . DIRECTORY_SEPARATOR) || !is_file($full)) {
            $this->abortFile(404, '文件不存在');
        }

        $ext = strtolower((string)($row['ext'] ?? ''));
        $raster = self::RASTER_TYPES[$ext] ?? null;
        $inline = !$download && $raster !== null && $this->isRealRaster($full, $raster[1]);

        // 安全响应头：禁止嗅探、禁止脚本与外部资源、禁止被框架嵌套
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: private, max-age=0, no-store');

        $name = (string)($row['name'] ?? '');
        if ($name === '') {
            $name = basename($full);
        }
        $name = preg_replace('/[^\w.\-]+/u', '_', $name) ?: 'download';
        $fallback = preg_replace('/[^\x20-\x7e]/', '_', $name) ?: 'download';

        header('Content-Type: ' . ($inline ? $raster[0] : 'application/octet-stream'));
        header(
            'Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
            . '; filename="' . str_replace('"', '', $fallback) . '"'
            . "; filename*=UTF-8''" . rawurlencode($name)
        );
        header('Content-Length: ' . (string)filesize($full));

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        readfile($full);
        exit;
    }

    /**
     * 校验相对路径：不得为空、不得包含 . / .. 段，也不得是绝对路径
     */
    private function isSafeRelativePath(string $path): bool
    {
        if ($path === '' || $path[0] === '/' || str_contains($path, "\0")) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }
        return true;
    }

    /**
     * 用 getimagesize 校验文件真实内容与扩展名一致
     *
     * 防止把 HTML/SVG/可执行内容改名为 .jpg 后绕过扩展名白名单，
     * 也避免「扩展名 jpg、内容 png」这类错配被浏览器按图片解析。
     */
    private function isRealRaster(string $full, int $expectedType): bool
    {
        $info = @getimagesize($full);
        return is_array($info) && (int)($info[2] ?? 0) === $expectedType;
    }

    /**
     * 直接输出纯文本错误，避免图片请求返回 JSON/HTML
     */
    private function abortFile(int $status, string $message): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo $message;
        exit;
    }

    private function formatSize(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }
}
