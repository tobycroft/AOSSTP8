<?php

namespace app\admin\controller;

use app\admin\service\AttachmentFileService;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use app\v3\file\service\AttachmentDeliveryService;
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
 * 安全说明：本地 Bucket 的图片预览统一使用 v3 分发服务的「时限取件码」
 * （/file/{code}.{ext}，默认 10 分钟有效），由 image 站的 nginx 鉴权后读盘输出，
 * PHP 不接触文件正文，也不向浏览器暴露真实 storage path：
 *   - 取件码由 AttachmentDeliveryService::issueTimed 签发，服务端 secret 签名；
 *   - 页面加载时签发一次，前端每 8 分钟调 preview_urls 批量换码，避免过期；
 *   - OSS Bucket 无法走取件码，保留原直链；
 *   - 只对光栅图片（jpg/jpeg/png/gif/bmp/webp/ico）inline 展示，
 *     SVG / HTML / PDF 等可能携带脚本的类型一律不内联，仅提供下载。
 *   - 历史 file() 受控代理保留作下载/兜底（要求 PHP 与文件同站，
 *     在 image 独立站的部署下不参与图片预览）。
 */
class V3Attachment extends CommonController
{
    /** 本站本地文件对外访问的图片站地址（OSS 直链兜底，本地预览不再用它） */
    private const IMAGE_BASE_URL = 'https://image.tuuz.cc:433';

    /** 后台图片预览取件码有效期（秒）：10 分钟 */
    private const PREVIEW_TTL = 600;

    /** 前端换码间隔（秒）：须小于 PREVIEW_TTL，留 2 分钟缓冲 */
    private const PREVIEW_REFRESH_SEC = 480;

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
            'previewRefreshSec' => self::PREVIEW_REFRESH_SEC,
            'previewTtl' => self::PREVIEW_TTL,
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

        // 对外展示地址：
        //   - 本地 Bucket：永久虚拟地址（隐藏真实 path），供复制/展示，不用于 <img>；
        //   - OSS Bucket：原直链，且作为唯一可用的对外地址。
        $isOss = $bucket && (string)$bucket['type'] === 'oss';
        $imageBase = rtrim((string)($bucket['url'] ?? ''), '/');
        if ($imageBase === '' && $isOss) {
            $imageBase = self::IMAGE_BASE_URL;
        }
        $directUrl = ($isOss && $imageBase !== '' && $this->isSafeRelativePath($path))
            ? $imageBase . '/' . $path
            : '';
        $publicUrl = $isOss
            ? $directUrl
            : AttachmentDeliveryService::publicUrl($row, $bucket ?: [], true);
        $proxyUrl = '/admin/v3_attachment/file?id=' . $id;

        // 实际文件地址（直连图片站真实 path，含端口；仅后台查看，不用于前台预览）
        // 域名取统一配置 attach_domain（已含 :433），空则回退常量；OSS 用原直链。
        $rawBase = rtrim(AttachmentDeliveryService::domain(), '/');
        if ($rawBase === '') {
            $rawBase = self::IMAGE_BASE_URL;
        }
        $rawUrl = ($isOss && $directUrl !== '')
            ? $directUrl
            : (($rawBase !== '' && $this->isSafeRelativePath($path)) ? $rawBase . '/' . $path : '');

        $isRaster = isset(self::RASTER_TYPES[$ext]);

        // 图片预览地址：本地光栅图走 10 分钟取件码（image 站鉴权读盘，PHP 不读正文）；
        // 签发失败时回退空，由前端显示占位。OSS 光栅图直接用原直链。
        $previewUrl = '';
        $previewExpiresAt = 0;
        if ($isRaster) {
            if ($isOss) {
                $previewUrl = $directUrl;
            } else {
                $issued = AttachmentDeliveryService::issueTimed($row, self::PREVIEW_TTL, $bucket ?: []);
                $previewUrl = (string)($issued['url'] ?? '');
                $previewExpiresAt = (int)($issued['expired_at'] ?? 0);
            }
        }
        // <img src> 优先取件码；本地图签发失败时空串，前端改用占位 badge
        $thumbUrl = $previewUrl;
        // 大图查看沿用同一取件码；OSS 也可直接打开
        $openUrl = $previewUrl !== '' ? $previewUrl : ($directUrl !== '' ? $directUrl : $proxyUrl);
        // 兜底代理：本地签发失败时仍可点开（同站部署可用；独立 image 站会失败，仅作最后兜底）
        $fallbackUrl = '';

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
            // 对外文件地址：本地=永久虚拟地址，OSS=原直链；供展示与复制
            'public_url' => $publicUrl,
            // 实际文件地址：图片站真实路径直链（含端口），仅后台查看
            'raw_url' => $rawUrl,
            // 时限取件码地址（本地光栅图 10 分钟；OSS 为直链；非图片为空）
            'preview_url' => $previewUrl,
            'preview_expires_at' => $previewExpiresAt,
            // 查看：图片走取件码/直链；其余走后台受控接口（远程 Bucket 会 302 到 OSS）
            'open_url' => $openUrl,
            // 下载：本地强制 attachment，远程直接使用 OSS 地址
            'download_url' => $isOss && $directUrl !== ''
                ? $directUrl
                : $proxyUrl . '&download=1',
        ];
    }

    /**
     * 批量刷新图片预览取件码（供后台列表定时换码）
     *
     * 入参 ids 逗号分隔；仅对本地 Bucket 的光栅图片重新签发，OSS/非图片
     * 不下发（其地址不带时效，前端保持不变）。返回 id => url 的映射。
     * 走后台登录鉴权（initialize），不额外暴露给匿名调用。
     */
    public function preview_urls()
    {
        $ids = trim((string)input('post.ids', ''));
        $idArr = array_values(array_filter(array_unique(array_map('intval', explode(',', $ids)))));
        if (empty($idArr) || count($idArr) > 500) {
            Ret::Fail(400, null, 'ids 需为 1~500 个附件 id');
        }

        $rows = Db::name('ao_attachment')
            ->whereIn('id', $idArr)
            ->where('is_deleted', 0)
            ->select()
            ->toArray();

        $urls = [];
        foreach ($rows as $row) {
            $ext = strtolower((string)($row['ext'] ?? ''));
            if (!isset(self::RASTER_TYPES[$ext])) {
                continue;
            }
            $path = str_replace('\\', '/', (string)($row['path'] ?? ''));
            $bucket = V3StorageProjectModel::api_match_by_path((string)($row['token'] ?? ''), $path);
            if (($bucket['type'] ?? 'local') === 'oss') {
                continue; // OSS 直链无时效，无需换码
            }
            $issued = AttachmentDeliveryService::issueTimed($row, self::PREVIEW_TTL, $bucket);
            if (!empty($issued['url'])) {
                $urls[(int)$row['id']] = $issued['url'];
            }
        }
        Ret::Success(0, ['urls' => $urls, 'expires_in' => self::PREVIEW_TTL]);
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
