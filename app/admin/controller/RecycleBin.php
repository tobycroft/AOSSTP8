<?php

namespace app\admin\controller;

use app\admin\service\AttachmentFileService;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;
use Ret;
use think\facade\Db;

/**
 * 附件回收站：统一查看 V1/V2/V3 软删附件，支持恢复与彻底删除
 */
class RecycleBin extends CommonController
{
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

    private function page()
    {
        $page = input('get.page', 1, 'intval');
        $limit = input('get.limit', 24, 'intval');
        $keyword = trim((string)input('get.keyword', ''));

        $query = Db::name('ao_attachment')->where('is_deleted', 1);
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
            $isV3 = (string)($row['tag'] ?? '') === 'v3' || str_starts_with((string)($row['token'] ?? ''), 'v3bucket:');
            $items[] = [
                'id' => (int)$row['id'],
                'name' => (string)($row['name'] ?? ''),
                'path' => (string)($row['path'] ?? ''),
                'recycle_path' => (string)($row['recycle_path'] ?? ''),
                'deleted_at' => (string)($row['deleted_at'] ?? ''),
                'size' => $this->formatSize((int)($row['size'] ?? 0)),
                'ext' => strtolower((string)($row['ext'] ?? '')),
                'md5' => (string)($row['md5'] ?? ''),
                'token' => (string)($row['token'] ?? ''),
                'source' => $isV3 ? 'V3' : 'V1/V2',
            ];
        }

        $extraParams = [];
        if ($keyword !== '') {
            $extraParams['keyword'] = $keyword;
        }
        if ($limit != 24) {
            $extraParams['limit'] = $limit;
        }
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
            'limit' => $limit,
            'pagination' => $pagination,
        ], '附件回收站', 'storage', 'recycle_bin');
    }

    private function purge()
    {
        $id = intval(request()->delete('id'));
        if ($id <= 0) {
            Ret::Fail(400, null, '缺少参数[id]');
        }
        $this->respond(AttachmentFileService::purge([$id]), '彻底删除成功');
    }

    private function batchPurge()
    {
        $ids = $this->readIds();
        $this->respond(AttachmentFileService::purge($ids), '批量彻底删除成功');
    }

    private function batchRestore()
    {
        $ids = $this->readIds();
        $this->respond(AttachmentFileService::restore($ids), '批量恢复成功');
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
