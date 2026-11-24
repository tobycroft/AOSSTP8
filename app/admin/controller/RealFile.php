<?php

namespace app\admin\controller;

use app\admin\service\RealFileService;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;
use Ret;
use think\facade\Db;

/**
 * 实际文件管理（独立菜单，独立于 V3 / ao_attachment 表结构）
 *
 * ao_real_file 只保存 public/upload 上的「无主文件」：扫描时读取 ao_attachment.path 比对，
 * 命中的有主文件不入库。删除分软删（移入 upload/recycle/YYYYMMDD/）与硬删（彻底删除）。
 */
class RealFile extends CommonController
{
    public function initialize()
    {
        parent::initialize();
        AdminAuth::requireLogin();
    }

    public function index()
    {
        $page = input('get.page', 1, 'intval');
        $limit = input('get.limit', 15, 'intval');
        if ($limit < 1 || $limit > 100) {
            $limit = 15;
        }
        $keyword = input('get.keyword', '', 'trim');
        $type = input('get.type', '', 'trim');
        $status = input('get.status', '', 'trim');
        $view = input('get.view', '', 'trim') === 'recycle' ? 'recycle' : 'normal';

        $query = Db::name('ao_real_file')->where('is_deleted', $view === 'recycle' ? 1 : 0);
        if ($keyword !== '') {
            $like = '%' . addcslashes($keyword, '\\%_') . '%';
            $query->where(function ($q) use ($like) {
                $q->where('path', 'like', $like)->whereOr('recycle_path', 'like', $like)
                    ->whereOr('name', 'like', $like);
            });
        }
        if ($type === '1' || $type === '2') {
            $query->where('type', (int)$type);
        }
        if ($status === '1' || $status === '0') {
            $query->where('status', (int)$status);
        }
        $query->order('type', 'asc')->order('path', 'asc');
        $list = $query->paginate($limit, false, ['page' => $page]);

        $currentPage = (int)$list->currentPage();
        $total = $list->total();
        $totalPages = max(1, (int)ceil($total / $limit));

        $extraParams = ['view' => $view];
        if ($keyword !== '') {
            $extraParams['keyword'] = $keyword;
        }
        if ($type !== '') {
            $extraParams['type'] = $type;
        }
        if ($status !== '') {
            $extraParams['status'] = $status;
        }
        if ($limit != 15) {
            $extraParams['limit'] = $limit;
        }

        $items = [];
        foreach ($list as $item) {
            $items[] = [
                'id' => (int)$item['id'],
                'path' => (string)$item['path'],
                'parent' => (string)$item['parent'],
                'name' => (string)$item['name'],
                'type' => (int)$item['type'],
                'size' => $this->formatSize((int)$item['size']),
                'ext' => (string)$item['ext'],
                'mime' => (string)$item['mime'],
                'mtime' => (int)$item['mtime'] > 0 ? date('Y-m-d H:i:s', (int)$item['mtime']) : '-',
                'status' => (int)$item['status'],
                'is_deleted' => (int)$item['is_deleted'],
                'recycle_path' => (string)($item['recycle_path'] ?? ''),
                'deleted_at' => !empty($item['deleted_at']) ? (string)$item['deleted_at'] : '-',
                'sync_time' => (int)$item['sync_time'] > 0 ? date('Y-m-d H:i:s', (int)$item['sync_time']) : '-',
            ];
        }

        $stats = [
            'files' => (int)Db::name('ao_real_file')->where('is_deleted', 0)->where('status', 1)->where('type', 1)->count(),
            'dirs' => (int)Db::name('ao_real_file')->where('is_deleted', 0)->where('status', 1)->where('type', 2)->count(),
            'recycled' => (int)Db::name('ao_real_file')->where('is_deleted', 1)->count(),
            'missing' => (int)Db::name('ao_real_file')->where('is_deleted', 0)->where('status', 0)->count(),
            'total' => (int)Db::name('ao_real_file')->where('is_deleted', 0)->count(),
            'last_sync' => Db::name('ao_real_file')->max('sync_time'),
        ];
        $stats['last_sync'] = $stats['last_sync'] > 0 ? date('Y-m-d H:i:s', (int)$stats['last_sync']) : '-';

        return $this->renderPage('real_file/index', [
            'list' => $items,
            'keyword' => $keyword,
            'type' => $type,
            'status' => $status,
            'view' => $view,
            'limit' => $limit,
            'limitOptions' => [15, 30, 50, 100],
            'pagination' => Layout::pagination($currentPage, $totalPages, '/admin/real_file', $extraParams, $limit),
            'stats' => $stats,
            'maxScan' => RealFileService::MAX_SCAN,
            'maxBatch' => RealFileService::MAX_BATCH,
        ], '实际文件管理', 'real_file', 'real_file');
    }

    /**
     * 手动全量扫描：按 ao_attachment.path 比对，只回填无主文件
     */
    public function sync()
    {
        $stats = RealFileService::scan();
        if (!empty($stats['errors'])) {
            Ret::Fail(500, $stats, '同步失败：' . implode('；', $stats['errors']));
        }

        $echo = "扫描完成：无主文件 {$stats['files']} 个、无主目录 {$stats['dirs']} 个；"
            . "有主跳过 {$stats['owned']} 个；新增 {$stats['created']} 条、回填 {$stats['updated']} 条、"
            . "变为有主移除 {$stats['removed']} 条、失效 {$stats['missing']} 条";
        if ($stats['truncated']) {
            $echo .= '；已达单次扫描上限 ' . RealFileService::MAX_SCAN . ' 条，本轮未标记失效';
        }
        if ($stats['skipped'] > 0) {
            $echo .= "；跳过 {$stats['skipped']} 个异常/回收站条目";
        }
        Ret::Success(0, $stats, $echo);
    }

    /**
     * 软删除：移入 upload/recycle/YYYYMMDD/，记录保留
     */
    public function softDelete()
    {
        $idArr = $this->readIds();
        $result = RealFileService::softDelete($idArr);
        $this->respond($result, '已移入回收站');
    }

    /**
     * 硬删除：磁盘文件与数据库记录彻底删除
     */
    public function hardDelete()
    {
        $idArr = $this->readIds();
        $result = RealFileService::hardDelete($idArr);
        $this->respond($result, '已彻底删除');
    }

    private function readIds(): array
    {
        $ids = input('post.ids', '', 'trim');
        $idArr = array_filter(array_map('intval', explode(',', $ids)), static fn(int $id): bool => $id > 0);
        if (empty($idArr)) {
            Ret::Fail(400, null, '未选择任何文件');
        }
        return $idArr;
    }

    private function respond(array $result, string $successEcho): void
    {
        if (!empty($result['errors']) && $result['deleted'] === 0) {
            Ret::Fail(500, $result, '操作失败：' . implode('；', $result['errors']));
        }
        $echo = $successEcho . '，共 ' . $result['deleted'] . ' 条';
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
