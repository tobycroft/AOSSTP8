<?php

namespace app\admin\model;

use think\Model;

/*
CREATE TABLE `ao_dnspod` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) DEFAULT NULL COMMENT '配置名称（人类可读标签）',
  `api_id` int unsigned NOT NULL DEFAULT 0 COMMENT 'DNSPod API ID',
  `api_token` varchar(128) NOT NULL DEFAULT '' COMMENT 'DNSPod API Token',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态：1启用 0禁用',
  `date` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='DNSPod 配置';
 */

class AdminDnspodModel extends Model
{
    protected $table = 'ao_dnspod';

    public function api_list($page, $limit)
    {
        return $this->order('id', 'desc')
            ->paginate($limit, false, ['page' => $page])
            ->toArray();
    }

    /**
     * 取出启用中的 DNSPod 配置，供在线测试复用。
     *
     * @param int $id
     * @return array ['api_id','api_token'] 或 []
     */
    public static function getConfig(int $id): array
    {
        if ($id <= 0) {
            return [];
        }
        $row = \think\facade\Db::name('ao_dnspod')
            ->field('api_id, api_token, status')
            ->where('id', $id)
            ->find();
        if (empty($row) || (int)$row['status'] !== 1) {
            return [];
        }
        return [
            'api_id' => (int)$row['api_id'],
            'api_token' => (string)$row['api_token'],
        ];
    }
}