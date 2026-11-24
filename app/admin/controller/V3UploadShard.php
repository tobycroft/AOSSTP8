<?php

namespace app\admin\controller;

use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use app\v3\storage\model\V3UploadShardModel;
use BaseController\CommonController;
use Ret;
use think\facade\Db;

/**
 * 存储管理 V3 → 目录压力
 *
 * 只读查看 ao_v3_upload_shard：每个 Bucket + 日期的当日上传序号，以及据此计算出的
 * 当前落盘子目录（upload/{bucket}/{YYYYMMDD}/{1000|2000|...}/）。
 * 计数是「分配序号」而非物理文件数（秒传不取号、删除不回退），仅用于观测目录压力分布。
 */
class V3UploadShard extends CommonController
{
    public function initialize()
    {
        parent::initialize();
        AdminAuth::requireLogin();
    }

    public function index()
    {
        return $this->page();
    }

    private function page()
    {
        $page = input('get.page', 1, 'intval');
        $limit = 20;
        $bucketId = input('get.bucket_id', 0, 'intval');
        $day = trim((string)input('get.day', ''));

        // Bucket / 项目 名称映射：分片表只存 bucket_id，展示时回填
        $buckets = Db::name('ao_v3_storage')->select()->toArray();
        $bucketMap = [];
        foreach ($buckets as $bucket) {
            $bucketMap[(int)$bucket['id']] = $bucket;
        }
        $projectNames = [];
        foreach (Db::name('ao_v3_project')->field('id, name')->select()->toArray() as $p) {
            $projectNames[(int)$p['id']] = (string)$p['name'];
        }
        // Bucket → 项目：新关联表优先，ao_v3_storage.project_id 仅作历史兜底
        $bucketProjectMap = [];
        foreach (Db::name('ao_v3_storage_project')->select()->toArray() as $binding) {
            $bid = (int)$binding['bucket_id'];
            if (!isset($bucketProjectMap[$bid])) {
                $bucketProjectMap[$bid] = (int)$binding['project_id'];
            }
        }
        foreach ($buckets as $bucket) {
            $bid = (int)$bucket['id'];
            if (!isset($bucketProjectMap[$bid])) {
                $bucketProjectMap[$bid] = (int)($bucket['project_id'] ?? 0);
            }
        }

        $query = Db::name('ao_v3_upload_shard')->order('id', 'desc');
        if ($bucketId > 0) {
            $query->where('bucket_id', $bucketId);
        }
        if ($day !== '') {
            $query->where('day', $day);
        }
        $list = $query->paginate($limit, false, ['page' => $page])->toArray();

        $items = [];
        foreach ($list['data'] as $row) {
            $bid = (int)$row['bucket_id'];
            $bucket = $bucketMap[$bid] ?? null;
            $pid = (int)($bucketProjectMap[$bid] ?? 0);
            $count = (int)($row['file_count'] ?? 0);
            $items[] = [
                'id' => (int)$row['id'],
                'bucket_id' => $bid,
                'bucket_name' => $bucket ? (string)$bucket['name'] : ('已删除 Bucket #' . $bid),
                'project_name' => $pid > 0 ? ($projectNames[$pid] ?? ('已删除项目(' . $pid . ')')) : '-',
                'day' => (string)$row['day'],
                'file_count' => $count,
                'segment' => V3UploadShardModel::segmentOf($count),
                'folder' => ($bucket ? ((string)($bucket['path'] ?? '') !== '' ? (string)$bucket['path'] : (string)$bucket['name']) : ('bucket#' . $bid))
                    . '/' . $row['day'] . '/' . V3UploadShardModel::segmentOf($count),
                'create_time' => (string)($row['create_time'] ?? '-'),
                'update_time' => (string)($row['update_time'] ?? '-'),
            ];
        }

        // 筛选下拉：列出全部 Bucket（含未关联项目的），与附件列表风格一致
        $bucketOptions = [];
        foreach ($buckets as $bucket) {
            $bid = (int)$bucket['id'];
            $pid = (int)($bucketProjectMap[$bid] ?? 0);
            $owner = $pid > 0 ? ($projectNames[$pid] ?? ('已删除项目(' . $pid . ')')) : '未关联项目';
            $bucketOptions[] = [
                'id' => $bid,
                'label' => $owner . ' / ' . (string)$bucket['name'],
            ];
        }

        $extraParams = [];
        if ($bucketId > 0) $extraParams['bucket_id'] = $bucketId;
        if ($day !== '') $extraParams['day'] = $day;

        $pagination = Layout::pagination(
            (int)$list['current_page'],
            max(1, (int)ceil($list['total'] / $limit)),
            '/admin/v3_upload_shard',
            $extraParams,
            $limit
        );

        return $this->renderPage('v3_upload_shard/index', [
            'list' => $items,
            'bucketOptions' => $bucketOptions,
            'bucketId' => $bucketId,
            'day' => $day,
            'pagination' => $pagination,
        ], '目录压力 V3', 'v3_storage', 'v3_upload_shard');
    }
}
