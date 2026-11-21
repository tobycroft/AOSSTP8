<?php

namespace app\v3\storage\model;

use app\v1\file\action\OssSelectionAction;
use think\facade\Db;
use think\Model;

/*
CREATE TABLE `ao_v3_storage`（V3 存储 Bucket）:
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `project_id` int unsigned NOT NULL COMMENT '所属 V3 项目 id（一项目一 Bucket）',
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT 'Bucket 名称，path 为空时作为落盘目录',
  `path` varchar(100) NOT NULL DEFAULT '' COMMENT '落盘目录，相对 upload/；留空回退 name',
  `ext` varchar(255) NOT NULL DEFAULT 'jpg,png,gif,bmp,jpeg' COMMENT '允许上传的后缀，逗号分隔',
  `size` int unsigned NOT NULL DEFAULT 16384 COMMENT '单文件大小上限（KB）',
  `type` enum('local','oss') NOT NULL DEFAULT 'local' COMMENT 'local 存本站，oss 传远程',
  `oss_type` enum('none','aliyun') NOT NULL DEFAULT 'none' COMMENT '远程凭据类型，type=oss 时固定 aliyun',
  `oss_tag` varchar(255) NOT NULL DEFAULT '' COMMENT '远程凭据标识，对应 ao_oss_aliyun.tag',
  `url` varchar(255) NOT NULL DEFAULT '' COMMENT '访问域名/URL 前缀，远程必填',
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `date` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_v3s_project` (`project_id`)

V3 存储模型是「Bucket」：一个项目对应一个 Bucket，Bucket 自己描述落盘 Path、
允许的后缀、大小上限、以及存本地还是传远程。不含 ao_oss 的 token/code/main_type
等历史概念；远程凭据按 oss_tag 复用 ao_oss_aliyun（仅凭据仓库，不是项目绑定关系）。
 */
class V3StorageModel extends Model
{
    protected $table = 'ao_v3_storage';

    /**
     * 按所属项目查找启用中的 Bucket（签发接口用）
     *
     * 用查询构造器直接取数组：避开 Model::toArray() 在 PHP 8.6 下对字符串属性
     * 调用 is_subclass_of(..., false) 触发的弃用告警。
     *
     * @param int $projectId ao_v3_project.id
     * @return array Bucket 行，或 []
     */
    public static function api_find_project(int $projectId): array
    {
        if ($projectId <= 0) {
            return [];
        }
        return \think\facade\Db::name('ao_v3_storage')
            ->where('project_id', $projectId)
            ->where('status', 1)
            ->findOrEmpty();
    }

    /**
     * 按 Bucket id 查找启用中的配置（直传落盘定位用）
     */
    public static function api_find_id(int $id): array
    {
        if ($id <= 0) {
            return [];
        }
        return \think\facade\Db::name('ao_v3_storage')
            ->where('id', $id)
            ->where('status', 1)
            ->findOrEmpty();
    }

    /**
     * 附件表的分组键：ao_attachment.token 存的就是它
     *
     * V3 直接使用「项目名称」作为 token（如 stb、flamecloud），便于后台和 DB
     * 直观区分文件归属；历史数据里的 v3bucket:{storage_id} 作为兼容兜底。
     * V3 上传同时把 ao_attachment.tag 记为 v3，避免与 V1/V2 的同名 token 混在一起。
     */
    public static function api_group_key(int $id): string
    {
        if ($id <= 0) {
            return '';
        }
        $name = Db::name('ao_v3_storage')
            ->alias('s')
            ->join('ao_v3_project p', 's.project_id = p.id')
            ->where('s.id', $id)
            ->value('p.name');
        return ($name !== null && $name !== '') ? (string)$name : ('v3bucket:' . $id);
    }

    /**
     * 按附件 token 反查 Bucket
     *
     * 兼容两种 token：
     *   - 项目名称（V3 现行），如 stb、flamecloud
     *   - v3bucket:{storage_id}（V3 历史数据）
     */
    public static function api_find_by_token(string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            return [];
        }
        if (preg_match('/^v3bucket:(\d+)$/', $token, $m) === 1) {
            return Db::name('ao_v3_storage')->where('id', (int)$m[1])->findOrEmpty();
        }
        return Db::name('ao_v3_storage')
            ->alias('s')
            ->join('ao_v3_project p', 's.project_id = p.id')
            ->where('p.name', $token)
            ->field('s.*')
            ->findOrEmpty();
    }

    /**
     * 把 Bucket 行组装成落盘用的 proc
     *
     * - type=oss 时按 oss_tag 合并 ao_oss_aliyun 凭据（accesskey/accesssecret/endpoint），
     *   复用 V1 的 OssSelectionAction，保证远程上传行为与 V1 一致；
     * - name 统一改写为落盘目录（path 优先，留空回退 name），
     *   V1 落盘逻辑即为 upload/{name}/YYYYMMDD/md5.ext。
     */
    public static function api_proc(array $bucket): array
    {
        if (empty($bucket)) {
            return [];
        }
        $proc = OssSelectionAction::App_find_byProc($bucket);
        $path = trim((string)($bucket['path'] ?? ''));
        $proc['name'] = $path !== '' ? $path : (string)($bucket['name'] ?? '');
        return $proc;
    }
}
