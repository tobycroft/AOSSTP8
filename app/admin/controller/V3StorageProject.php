<?php

namespace app\admin\controller;

use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use app\v3\storage\model\V3StorageProjectModel;
use Aoss\Envelope;
use BaseController\CommonController;
use Input;
use Ret;
use think\facade\Db;

/**
 * 存储管理 V3 → 存储项目
 *
 * 维护「V3 项目（secret）↔ 一个或多个 Bucket」的授权关系，并指定默认 Bucket：
 *   - 只有先在「Bucket 配置」里创建的 Bucket，才能在这里勾选关联；
 *   - 请求未传 bucket 时用默认 Bucket；传了 bucket 时必须是这里勾选过的。
 *
 * 关联表 ao_v3_storage_project 是归属关系的唯一来源；ao_v3_storage.project_id
 * 仅保留为历史兼容列，不参与任何判断。
 */
class V3StorageProject extends CommonController
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
        if ($method === 'POST') {
            return $this->save();
        }
        Ret::Fail(405, null, '不支持的请求方法');
    }

    private function page()
    {
        $limit = 15;
        $search = trim((string)input('get.search', ''));
        $page = (int)input('get.page', 1, 'intval');

        $query = Db::name('ao_v3_project')->order('id', 'desc');
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%');
                if (preg_match('/^[0-9a-fA-F]{64}$/', $search) === 1) {
                    $q->whereOr('secret', '=', strtolower($search));
                }
            });
        }
        $list = $query->paginate($limit, false, ['page' => $page])->toArray();

        // Bucket 全集：只有在这里已有的 Bucket 才能被关联
        $buckets = Db::name('ao_v3_storage')->order('id', 'asc')->select()->toArray();
        $bucketMap = [];
        foreach ($buckets as $bucket) {
            $bucketMap[(int)$bucket['id']] = $bucket;
        }

        // 现有授权关系：按项目聚合
        $bindings = Db::name('ao_v3_storage_project')->select()->toArray();
        $byProject = [];
        foreach ($bindings as $binding) {
            $pid = (int)$binding['project_id'];
            $bid = (int)$binding['bucket_id'];
            $byProject[$pid]['ids'][] = $bid;
            if ((int)$binding['is_default'] === 1) {
                $byProject[$pid]['default'] = $bid;
            }
        }

        $items = [];
        foreach ($list['data'] as $project) {
            $pid = (int)$project['id'];
            $ids = $byProject[$pid]['ids'] ?? [];
            $ids = array_values(array_unique(array_map('intval', $ids)));
            $defaultId = (int)($byProject[$pid]['default'] ?? 0);
            if ($defaultId <= 0 && !empty($ids)) {
                $defaultId = $ids[0];
            }

            $labels = [];
            foreach ($ids as $bid) {
                $bucket = $bucketMap[$bid] ?? null;
                $labels[] = $bucket
                    ? ((string)$bucket['name'] . '（' . ((string)$bucket['path'] !== '' ? $bucket['path'] : '默认路径') . '）')
                    : ('已删除 Bucket #' . $bid);
            }
            $defaultBucket = $bucketMap[$defaultId] ?? null;

            $secret = (string)($project['secret'] ?? '');
            $items[] = [
                'id' => $pid,
                'name' => (string)($project['name'] ?? ''),
                'kid' => Envelope::kid($secret),
                'secret_mask' => $secret === '' ? '-' : mb_substr($secret, 0, 6) . '····' . mb_substr($secret, -4),
                'status' => (int)($project['status'] ?? 0),
                'bucket_ids' => implode(',', $ids),
                'bucket_labels' => implode('、', $labels),
                'bucket_count' => count($ids),
                'default_bucket_id' => $defaultId,
                'default_label' => $defaultBucket ? (string)$defaultBucket['name'] : ($defaultId > 0 ? ('已删除 Bucket #' . $defaultId) : '-'),
            ];
        }

        $bucketOptions = [];
        foreach ($buckets as $bucket) {
            $bucketOptions[] = [
                'id' => (int)$bucket['id'],
                'name' => (string)$bucket['name'],
                'path' => (string)($bucket['path'] ?? ''),
                'type' => (string)$bucket['type'],
                'status' => (int)$bucket['status'],
            ];
        }

        $pagination = Layout::pagination(
            (int)$list['current_page'],
            max(1, (int)ceil($list['total'] / $limit)),
            '/admin/v3_storage_project',
            ['search' => $search],
            $limit
        );

        return $this->renderPage('v3_storage_project/index', [
            'list' => $items,
            'bucketOptions' => $bucketOptions,
            'search' => $search,
            'pagination' => $pagination,
        ], '存储项目 V3', 'v3_storage', 'v3_storage_project');
    }

    /** 保存授权关系（全量覆盖当前项目的勾选） */
    private function save()
    {
        $projectId = Input::PostInt('project_id', 0);
        $raw = request()->post('bucket_ids', []);
        if (is_array($raw)) {
            $bucketIds = $raw;
        } elseif (trim((string)$raw) !== '') {
            $bucketIds = explode(',', (string)$raw);
        } else {
            $bucketIds = [];
        }
        $defaultBucketId = Input::PostInt('default_bucket_id', 0);

        if (!V3StorageProjectModel::api_save($projectId, $bucketIds, $defaultBucketId)) {
            Ret::Fail(400, null, '保存失败：项目或 Bucket 不存在，或默认 Bucket 不在勾选列表中');
        }
        Ret::Success(0, [], '保存成功');
    }
}
