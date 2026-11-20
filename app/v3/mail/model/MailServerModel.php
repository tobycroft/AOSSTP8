<?php

namespace app\v3\mail\model;

use think\Model;

/*
两个关联表（建表语句同步在 admin 侧 AdminMailServerModel 注释中）：
  ao_mail_server          SMTP 服务器配置
  ao_v3_project_mail      项目与邮件服务器的多对多授权（is_default 标记实际发信服务器）

v3 邮件接口凭 ao_v3_project 的通用凭证（信封鉴权），
「能发信」的前提是项目被授权至少一台启用中的邮件服务器，
与 AI 应用「项目须授权 agnes key」的约束一致。
*/
class MailServerModel extends Model
{
    protected $table = 'ao_mail_server';

    /**
     * 项目是否拥有至少一台启用中的邮件服务器（鉴权阶段判定项目可用性）
     */
    public static function hasAnyServer(int $projectId): bool
    {
        if ($projectId <= 0) {
            return false;
        }
        $count = \think\facade\Db::name('ao_v3_project_mail')
            ->alias('pm')
            ->join('ao_mail_server s', 's.id = pm.mail_server_id')
            ->where('pm.project_id', $projectId)
            ->where('s.status', 1)
            ->count();
        return $count > 0;
    }

    /**
     * 项目默认发信服务器：优先 is_default=1，未设默认时取授权列表中 id 最小的一台兜底
     *
     * 只返回启用中的服务器；服务器被禁用等同未授权。
     *
     * @return array ['id','name','host','port','encryption','username','password','from_addr','from_name'] 或 []
     */
    public static function defaultServer(int $projectId): array
    {
        if ($projectId <= 0) {
            return [];
        }
        $ids = \think\facade\Db::name('ao_v3_project_mail')
            ->alias('pm')
            ->join('ao_mail_server s', 's.id = pm.mail_server_id')
            ->where('pm.project_id', $projectId)
            ->where('s.status', 1)
            ->order('pm.is_default', 'desc')
            ->order('s.id', 'asc')
            ->column('s.id');
        if (empty($ids)) {
            return [];
        }
        return self::serverConfig((int)$ids[0]);
    }

    /**
     * 取项目已授权的指定服务器（send 可显式指定 server_id）
     */
    public static function authorizedServer(int $projectId, int $serverId): array
    {
        if ($projectId <= 0 || $serverId <= 0) {
            return [];
        }
        $authorized = \think\facade\Db::name('ao_v3_project_mail')
            ->where('project_id', $projectId)
            ->where('mail_server_id', $serverId)
            ->find();
        if (empty($authorized)) {
            return [];
        }
        return self::serverConfig($serverId);
    }

    /**
     * 按服务器 id 取完整 SMTP 配置（仅启用中）
     */
    private static function serverConfig(int $serverId): array
    {
        $row = self::field('id, name, host, port, encryption, username, password, from_addr, from_name, status')
            ->where('id', $serverId)
            ->where('status', 1)
            ->find();
        return empty($row) ? [] : $row->toArray();
    }
}
