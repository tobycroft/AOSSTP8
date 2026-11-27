<?php

namespace app\v3\project\model;

use think\Model;

/*
CREATE TABLE `ao_v3_project`:
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) DEFAULT NULL COMMENT '项目名称（人类可读标签，非凭证）',
  `rate_limit` int unsigned DEFAULT 0 COMMENT 'v3接口每60秒请求上限，0表示不限速',
  `model_alias` varchar(64) DEFAULT '' COMMENT '对外返回的模型名别名（AI 应用），为空回退 aoss',
  `secret` varchar(64) NOT NULL DEFAULT '' COMMENT 'v3 凭证，64 位随机十六进制，同时作为信封加密密钥',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态',
  `date` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_secret` (`secret`)

v3 项目为应用无关的通用凭证：一个项目（secret）可使用 v3 下全部应用，
只维护鉴权信息（name / secret / rate_limit / model_alias / status），不承载业务配置。
AI 应用凭「授权」使用 agnes key：ao_v3_project_agnes（多对多，is_default
标记 AI 请求实际使用的 key）；后台在「AI 管理 → AI 授权」中维护。
文件上传应用的存储配置在独立表 ao_v3_storage 中维护（后台「存储管理 V3」），
一个项目可通过 ao_v3_storage_project 关联多个 Bucket，默认 Bucket 可选；未设默认时请求必须显式指定 bucket
（后台「存储项目」），与 ao_oss 无关。
local 存本站 ./upload/，oss 传远程，存储类型逻辑完全复用 V1。
 */

class V3ProjectModel extends Model
{
    protected $table = 'ao_v3_project';

    /** 单请求内缓存：后台附件列表逐行签发取件码时避免重复查库 */
    private static array $secretCache = [];

    /**
     * 预热缓存：后台列表页已读入项目名/id/secret，直接灌入避免逐行查库。
     * @param array $projectNames 项目 id => 项目名
     * @param array $projectSecrets 项目 id => secret（仅 status=1）
     */
    public static function prime(array $projectNames, array $projectSecrets): void
    {
        foreach ($projectNames as $id => $name) {
            // 只预热启用项目：停用项目留空，让 api_secret_by_attachment 走原 path 反查兜底
            if (!array_key_exists((int)$id, $projectSecrets)) {
                continue;
            }
            self::$secretCache['t:' . (string)$name] = (string)$projectSecrets[(int)$id];
        }
    }

    /**
     * 按凭证定位标识（kid）查找可用项目
     *
     * kid = substr(sha256(secret), 0, 8)，与 Aoss\Envelope::kid() 保持一致；
     * 比对在 SQL 侧完成，secret 本身不出现在任何请求参数中。
     * 归一化为小写，避免依赖字段排序规则的大小写敏感性。
     *
     * @param string $kid 8 位小写十六进制定位标识
     * @return array
     */
    public static function api_find_kid(string $kid): array
    {
        if (preg_match('/^[0-9a-f]{8}$/', $kid) !== 1) {
            return [];
        }
        // 用查询构造器直接取数组：避开 Model::toArray() 在 PHP 8.6 下对字符串属性
        // 调用 is_subclass_of(..., false) 触发的弃用告警（该告警在 debug 下会中断请求）。
        // 凭证查询只需原始行，无需模型属性/关联转换。
        return \think\facade\Db::name('ao_v3_project')
            ->where('status', 1)
            ->whereRaw('LOWER(LEFT(SHA2(`secret`, 256), 8)) = ?', [$kid])
            ->findOrEmpty();
    }

    /**
     * 取附件所属项目的签名密钥（v3 取件码用）
     *
     * 优先按 ao_attachment.token（项目名）定位；历史 token=v3bucket:{id}
     * 或项目已改名时，用 path 反查 Bucket，再经 ao_v3_storage_project 找项目。
     * 密钥只用于服务端签发/验签，不下发到取件码本身。
     */
    public static function api_secret_by_attachment(array $row): string
    {
        $token = trim((string)($row['token'] ?? ''));
        $path = str_replace('\\', '/', (string)($row['path'] ?? ''));
        $isLegacy = preg_match('/^v3bucket:(\d+)$/', $token, $m) === 1;
        $cacheKey = $isLegacy ? 'b:' . (int)$m[1] : 't:' . $token;
        if (array_key_exists($cacheKey, self::$secretCache)) {
            return self::$secretCache[$cacheKey];
        }
        $bucketId = 0;
        $projectId = 0;

        if ($token !== '' && !$isLegacy) {
            $projectId = (int)\think\facade\Db::name('ao_v3_project')
                ->where('name', $token)
                ->where('status', 1)
                ->value('id');
        }

        if ($projectId <= 0) {
            if ($isLegacy) {
                $bucketId = (int)$m[1];
            } else {
                $bucket = \app\v3\storage\model\V3StorageProjectModel::api_match_by_path($token, $path);
                $bucketId = (int)($bucket['id'] ?? 0);
            }
            if ($bucketId > 0) {
                $projectId = (int)\think\facade\Db::name('ao_v3_storage_project')
                    ->where('bucket_id', $bucketId)
                    ->where('status', 1)
                    ->order('is_default', 'desc')
                    ->order('id', 'asc')
                    ->value('project_id');
            }
        }

        if ($projectId <= 0) {
            return self::$secretCache[$cacheKey] = '';
        }
        return self::$secretCache[$cacheKey] = trim((string)\think\facade\Db::name('ao_v3_project')
            ->where('id', $projectId)
            ->where('status', 1)
            ->value('secret'));
    }

    /**
     * 取项目被授权且启用中的默认 Agnes API 配置
     *
     * 仅返回 is_default=1 的授权；对应 Agnes API 不存在或被禁用时返回空数组
     * （授权仍在但 key 已失效，等同未授权，AI 应用据此判定不可用）。
     *
     * @param int $projectId ao_v3_project.id
     * @return array ['api_key','base_url','model'] 或 []
     */
    public static function defaultAgnes(int $projectId): array
    {
        if ($projectId <= 0) {
            return [];
        }
        $agnesId = \think\facade\Db::name('ao_v3_project_agnes')
            ->where('project_id', $projectId)
            ->where('is_default', 1)
            ->value('agnes_api_id');
        if (empty($agnesId)) {
            return [];
        }
        $row = \think\facade\Db::name('ao_ai_agnes')
            ->field('api_key, base_url, model, status')
            ->where('id', (int)$agnesId)
            ->find();
        if (empty($row) || (int)$row['status'] !== 1) {
            return [];
        }
        return [
            'api_key' => $row['api_key'],
            'base_url' => $row['base_url'],
            'model' => $row['model'],
        ];
    }
}
