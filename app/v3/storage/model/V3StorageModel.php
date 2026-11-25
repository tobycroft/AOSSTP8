<?php

namespace app\v3\storage\model;

use app\v1\file\action\OssSelectionAction;
use think\facade\Db;
use think\Model;

/*
CREATE TABLE `ao_v3_storage`（V3 存储 Bucket）:
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `project_id` int unsigned NOT NULL DEFAULT 0 COMMENT '历史兼容字段，已弃用；归属关系见 ao_v3_storage_project',
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
  PRIMARY KEY (`id`)
  注：旧版唯一键 uk_v3s_project(project_id) 需 DROP，否则同一项目无法关联多个 Bucket。

V3 存储模型是「Bucket」：Bucket 自己描述落盘 Path、允许的后缀、大小上限、
以及存本地还是传远程。项目与 Bucket 的一对多关系在 ao_v3_storage_project 中维护，
一个项目（secret）可授权多个 Bucket，默认 Bucket 可选；未设默认时请求必须显式指定 bucket。
不含 ao_oss 的 token/code/main_type 等历史概念；远程凭据按 oss_tag 复用
ao_oss_aliyun（仅凭据仓库，不是项目绑定关系）。
 */
class V3StorageModel extends Model
{
    protected $table = 'ao_v3_storage';

    /**
     * 旧版「一项目一 Bucket」查询，已弃用
     *
     * 新逻辑请用 V3StorageProjectModel::api_resolve()：支持按请求指定的 bucket
     * 选授权 Bucket，未指定时只取默认 Bucket（未设置默认返回空）。本方法仅为兼容保留，
     * 不再参与 V3 签发/直传链路。
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
     * 按 Bucket 的 path（留空回退 name）查找启用中的配置
     *
     * 虚拟地址 /attachment/{bucket_path}/{md5} 用可读 path 代替数字 id，
     * 避免暴露自增主键；path 是文件落盘前缀，天然唯一且稳定。
     */
    public static function api_find_path(string $path): array
    {
        $path = trim($path, '/');
        if ($path === '' || preg_match('/^[A-Za-z0-9._\-]+$/', $path) !== 1) {
            return [];
        }
        $row = \think\facade\Db::name('ao_v3_storage')
            ->where('status', 1)
            ->where('path', $path)
            ->findOrEmpty();
        if (empty($row)) {
            $row = \think\facade\Db::name('ao_v3_storage')
                ->where('status', 1)
                ->where('path', '')
                ->where('name', $path)
                ->findOrEmpty();
        }
        return $row;
    }

    /**
     * 附件表的分组键：ao_attachment.token 存的就是它
     *
     * V3 直接使用「项目名称」作为 token（如 stb、flamecloud），归属从
     * ao_v3_storage_project 关联表读取；历史数据里的 v3bucket:{storage_id}
     * 作为兼容兜底（关联缺失时）。
     * V3 上传同时把 ao_attachment.tag 记为 v3，避免与 V1/V2 的同名 token 混在一起。
     */
    public static function api_group_key(int $id): string
    {
        if ($id <= 0) {
            return '';
        }
        // 归属关系已迁到关联表（新建 Bucket 的 project_id 固定为 0），
        // 不能再从 ao_v3_storage.project_id 关联项目名，否则分组键退化。
        $projectId = (int)Db::name('ao_v3_storage_project')
            ->where('bucket_id', $id)
            ->where('status', 1)
            ->order('is_default', 'desc')
            ->order('id', 'asc')
            ->value('project_id');
        $name = $projectId > 0
            ? Db::name('ao_v3_project')->where('id', $projectId)->value('name')
            : null;
        return ($name !== null && $name !== '') ? (string)$name : ('v3bucket:' . $id);
    }

    /**
     * 按附件 token 反查 Bucket（兼容旧调用）
     *
     * 兼容两种 token：
     *   - 项目名称（V3 现行），如 stb、flamecloud；现一个项目可能有多个 Bucket，
     *     此处只返回默认 Bucket，未设置默认时返回空。能拿到 attachment.path 时
     *     应优先用 V3StorageProjectModel::api_match_by_path() 精确定位。
     *   - v3bucket:{storage_id}（V3 历史数据）
     */
    public static function api_find_by_token(string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            return [];
        }
        if (preg_match('/^v3bucket:(\d+)$/', $token, $m) === 1) {
            return self::api_find_id((int)$m[1]);
        }
        $projectId = (int)Db::name('ao_v3_project')->where('name', $token)->value('id');
        if ($projectId <= 0) {
            return [];
        }
        return V3StorageProjectModel::api_resolve($projectId, '');
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
