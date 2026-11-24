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
