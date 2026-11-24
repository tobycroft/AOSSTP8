<?php

namespace app\admin\controller;

use app\admin\service\RealFileService;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;
use Ret;
use think\facade\Db;

/**
 * 实际文件管理（独立菜单，独立于 V3 / ao_attachment）
 *
 * 数据只来自 ao_real_file：由管理员手动触发扫描 public/upload 回填，
 * 删除时同步删除磁盘文件与数据库记录。
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

        $query = Db::name('ao_real_file')->where('id', '>', 0);
        if ($keyword !== '') {
            $query->where('path', 'like', '%' . addcslashes($keyword, '\\%_') . '%');
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

        $extraParams = [];
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
                'sync_time' => (int)$item['sync_time'] > 0 ? date('Y-m-d H:i:s', (int)$item['sync_time']) : '-',
            ];
        }

        $stats = [
            'files' => (int)Db::name('ao_real_file')->where('status', 1)->where('type', 1)->count(),
            'dirs' => (int)Db::name('ao_real_file')->where('status', 1)->where('type', 2)->count(),
            'missing' => (int)Db::name('ao_real_file')->where('status', 0)->count(),
            'total' => (int)Db::name('ao_real_file')->count(),
            'last_sync' => Db::name('ao_real_file')->max('sync_time'),
        ];
        $stats['last_sync'] = $stats['last_sync'] > 0 ? date('Y-m-d H:i:s', (int)$stats['last_sync']) : '-';

        return $this->renderPage('real_file/index', [
            'list' => $items,
            'keyword' => $keyword,
            'type' => $type,
            'status' => $status,
            'limit' => $limit,
            'limitOptions' => [15, 30, 50, 100],
            'pagination' => Layout::pagination($currentPage, $totalPages, '/admin/real_file', $extraParams, $limit),
            'stats' => $stats,
            'maxScan' => RealFileService::MAX_SCAN,
            'maxBatch' => RealFileService::MAX_BATCH,
        ], '实际文件管理', 'real_file', 'real_file');
    }

    /**
     * 手动全量扫描（全局遍历动作，仅在点击同步按钮时触发）
     */
    public function sync()
    {
        $stats = RealFileService::scan();
        if (!empty($stats['errors'])) {
            Ret::Fail(500, $stats, '同步失败：' . implode('；', $stats['errors']));
        }

        $echo = "扫描完成：文件 {$stats['files']} 个、目录 {$stats['dirs']} 个；"
            . "新增 {$stats['created']} 条、回填 {$stats['updated']} 条、标记失效 {$stats['missing']} 条";
        if ($stats['truncated']) {
            $echo .= '；已达单次扫描上限 ' . RealFileService::MAX_SCAN . ' 条，本轮未标记失效';
        }
        if ($stats['skipped'] > 0) {
            $echo .= "；跳过 {$stats['skipped']} 个异常条目";
        }
        Ret::Success(0, $stats, $echo);
    }

    /**
     * 同步删除：所选记录的磁盘文件/目录 + 数据库记录一并删除
     */
    public function delete()
    {
        $ids = input('post.ids', '', 'trim');
        if ($ids === '') {
            Ret::Fail(400, null, '未选择任何文件');
        }
        $idArr = array_filter(array_map('intval', explode(',', $ids)), static fn(int $id): bool => $id > 0);
        if (empty($idArr)) {
            Ret::Fail(400, null, '无效的ID');
        }

        $result = RealFileService::delete($idArr);
        if (!empty($result['errors'])) {
            Ret::Fail(500, $result, '部分删除失败：' . implode('；', $result['errors']));
        }

        $echo = "已删除 {$result['deleted']} 条记录及对应文件";
        if (!empty($result['warnings'])) {
            $echo .= '；' . implode('；', $result['warnings']);
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
