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
一项目一存储，通过 storage.project_id 关联，与 ao_oss 无关。
local 存本站 ./upload/，oss 传远程，存储类型逻辑完全复用 V1。
 */

class V3ProjectModel extends Model
{
    protected $table = 'ao_v3_project';

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
