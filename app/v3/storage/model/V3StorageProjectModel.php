<?php

namespace app\v3\storage\model;

use think\facade\Db;
use think\Model;

/*
CREATE TABLE `ao_v3_storage_project`（V3 存储项目：项目与 Bucket 的一对多关联）:
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `project_id` int unsigned NOT NULL COMMENT 'V3 项目 id（ao_v3_project.id，对应一个 secret）',
  `bucket_id` int unsigned NOT NULL COMMENT 'Bucket id（ao_v3_storage.id）',
  `is_default` tinyint(1) NOT NULL DEFAULT 0 COMMENT '默认 Bucket：请求未指定 bucket 时使用；每个项目最多一条为 1',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=启用 0=停用',
  `date` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_project_bucket` (`project_id`, `bucket_id`),
  KEY `idx_project_default` (`project_id`, `is_default`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='V3 存储项目：项目(secret)与 Bucket 的一对多关联及默认 Bucket';

从旧的一对一结构迁移（在 ao_v3_storage.project_id 仍保留历史值时执行）：
  INSERT INTO ao_v3_storage_project (project_id, bucket_id, is_default, status)
  SELECT project_id, id, 1, status FROM ao_v3_storage WHERE project_id > 0
  ON DUPLICATE KEY UPDATE is_default = 1;

  ALTER TABLE ao_v3_storage DROP INDEX `uk_v3s_project`;
  说明：不 DROP project_id 列，仅保留为历史兼容字段；新建 Bucket 写 0，
        归属关系一律以本表为准。

接口行为：
  - 请求未传 bucket  → 只取 is_default=1 的 Bucket；后台未设置默认时返回空，签发接口拒绝；
  - 请求传了 bucket  → 必须是该项目已授权的 Bucket（按 name 精确匹配，也兼容纯数字 id），
                       否则返回空，由控制器拒绝，避免越权上传。
  - 默认 Bucket 是可选配置：允许一个项目只关联多个 Bucket 而不设默认，
    此时调用方必须显式指定 bucket。
 */
class V3StorageProjectModel extends Model
{
    protected $table = 'ao_v3_storage_project';

    /** 单请求内缓存：后台列表逐行反查 Bucket 时避免重复查库（PHP-FPM 每请求重建） */
    private static array $projectIdByToken = [];
    private static array $bucketsByProject = [];

    /**
     * 项目已授权的启用 Bucket 列表（默认 Bucket 排在最前）
     *
     * 同时过滤关联表 status 与 Bucket 自身 status，任一停用即不可用。
     * 用查询构造器取数组，避开 Model 在 PHP 8.6 下的弃用告警。
     *
     * @return array 每项为 Bucket 行 + sp_is_default / sp_id
     */
    public static function api_buckets(int $projectId): array
    {
        if ($projectId <= 0) {
            return [];
        }
        return Db::name('ao_v3_storage_project')
            ->alias('sp')
            ->join('ao_v3_storage s', 's.id = sp.bucket_id')
            ->where('sp.project_id', $projectId)
            ->where('sp.status', 1)
            ->where('s.status', 1)
            ->field('s.*, sp.is_default AS sp_is_default, sp.id AS sp_id')
            ->order('sp.is_default', 'desc')
            ->order('sp.id', 'asc')
            ->select()
            ->toArray();
    }

    /**
     * 解析本次请求应使用的 Bucket
     *
     * @param int $projectId 已鉴权的 V3 项目 id
     * @param string $bucket 请求指定的 bucket（Bucket name；纯数字兼容 Bucket id），空表示走默认
     * @return array Bucket 行，未授权 / 未配置时返回 []
     */
    public static function api_resolve(int $projectId, string $bucket): array
    {
        $buckets = self::api_buckets($projectId);
        if (empty($buckets)) {
            return [];
        }

        $bucket = trim($bucket);
        if ($bucket === '') {
            // 默认 Bucket 可为空：未设置时不再兜底取第一个，调用方必须显式指定 bucket。
            foreach ($buckets as $item) {
                if ((int)($item['sp_is_default'] ?? 0) === 1) {
                    return $item;
                }
            }
            return [];
        }

        // 优先按 Bucket name 精确匹配（大小写不敏感）；path 留空时 name 即落盘目录
        foreach ($buckets as $item) {
            if (strcasecmp((string)($item['name'] ?? ''), $bucket) === 0) {
                return $item;
            }
        }
        // 兼容直接传 Bucket id 的新 SDK：纯数字才当 id，避免与同名 Bucket 混淆
        if (ctype_digit($bucket)) {
            $bucketId = (int)$bucket;
            foreach ($buckets as $item) {
                if ((int)$item['id'] === $bucketId) {
                    return $item;
                }
            }
        }
        return [];
    }

    /**
     * 按附件的 token + path 反查所属 Bucket（后台附件列表/预览用）
     *
     * token 为项目名时，一个项目可能有多个 Bucket，不能再按项目名唯一映射：
     * 用 path 的前缀（Bucket 的 path 或 name，取最长匹配）定位；找不到时回退默认 Bucket，
     * 未设默认则回退第一个关联 Bucket（仅用于后台展示/存储类型判断，不用于签发）。
     * 历史 token v3bucket:{id} 直接按 id 定位。
     */
    public static function api_match_by_path(string $token, string $path): array
    {
        $token = trim($token);
        if (preg_match('/^v3bucket:(\d+)$/', $token, $m) === 1) {
            return V3StorageModel::api_find_id((int)$m[1]);
        }

        if (array_key_exists($token, self::$projectIdByToken)) {
            $projectId = self::$projectIdByToken[$token];
        } else {
            $projectId = (int)Db::name('ao_v3_project')->where('name', $token)->value('id');
            self::$projectIdByToken[$token] = $projectId;
        }
        if ($projectId <= 0) {
            return [];
        }
        if (!array_key_exists($projectId, self::$bucketsByProject)) {
            self::$bucketsByProject[$projectId] = self::api_buckets($projectId);
        }
        $buckets = self::$bucketsByProject[$projectId];
        if (empty($buckets)) {
            return [];
        }

        $path = str_replace('\\', '/', trim($path));
        $best = [];
        $bestLength = -1;
        foreach ($buckets as $item) {
            foreach ([(string)($item['path'] ?? ''), (string)($item['name'] ?? '')] as $raw) {
                $prefix = trim($raw, '/');
                if ($prefix === '' || str_contains($prefix, '\\') || str_contains($prefix, ':')) {
                    continue;
                }
                if (str_starts_with($path, $prefix . '/') && strlen($prefix) > $bestLength) {
                    $best = $item;
                    $bestLength = strlen($prefix);
                }
            }
        }
        if (!empty($best)) {
            return $best;
        }
        foreach ($buckets as $item) {
            if ((int)($item['sp_is_default'] ?? 0) === 1) {
                return $item;
            }
        }
        return $buckets[0];
    }

    /**
     * 保存项目的 Bucket 授权关系（后台「存储项目」页调用）
     *
     * 全量覆盖：先删旧关联再插入新关联；默认 Bucket 是可选的，未指定时全部写 0，
     * 此时请求必须显式带 bucket，签发接口才会放行。
     * 只允许关联 Bucket 管理中已存在的 Bucket（停用中的也允许先关联，不生效）。
     *
     * @param array $bucketIds 勾选的 Bucket id
     * @return bool 成功 true；项目不存在 / Bucket 不存在 / 默认不在勾选内 / 写库失败均 false
     */
    public static function api_save(int $projectId, array $bucketIds, int $defaultBucketId): bool
    {
        if ($projectId <= 0 || Db::name('ao_v3_project')->where('id', $projectId)->count() === 0) {
            return false;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $bucketIds))));
        if (!empty($ids)) {
            $exists = Db::name('ao_v3_storage')->whereIn('id', $ids)->column('id');
            if (count($exists) !== count($ids)) {
                return false;
            }
        }
        if ($defaultBucketId > 0 && !in_array($defaultBucketId, $ids, true)) {
            return false;
        }

        Db::startTrans();
        try {
            Db::name('ao_v3_storage_project')->where('project_id', $projectId)->delete();
            foreach ($ids as $bucketId) {
                Db::name('ao_v3_storage_project')->insert([
                    'project_id' => $projectId,
                    'bucket_id' => $bucketId,
                    'is_default' => ($bucketId === $defaultBucketId ? 1 : 0),
                    'status' => 1,
                    'date' => date('Y-m-d H:i:s'),
                ]);
            }
            Db::commit();
            return true;
        } catch (\Throwable $e) {
            Db::rollback();
            return false;
        }
    }
}
