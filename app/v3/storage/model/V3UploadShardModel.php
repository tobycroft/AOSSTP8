<?php

namespace app\v3\storage\model;

use think\facade\Db;
use think\Model;

/*
CREATE TABLE `ao_v3_upload_shard`（V3 上传分片计数）:
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `bucket_id` int unsigned NOT NULL COMMENT 'Bucket id（ao_v3_storage.id）',
  `day` varchar(8) NOT NULL COMMENT '日期 YYYYMMDD',
  `file_count` int unsigned NOT NULL DEFAULT 0 COMMENT '当日已分配的序号（原子自增，非物理文件数）',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bucket_day` (`bucket_id`, `day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='V3 每 Bucket 每天上传分片计数';

说明：
  - 每个 Bucket + 日期一行，file_count 只增不减；用于把当天上传分摊到
    upload/{bucket}/{YYYYMMDD}/{1000|2000|...}/ 子目录，避免单目录文件堆积。
  - 取号刻意不使用 transaction / SELECT FOR UPDATE：用一条
    INSERT ... ON DUPLICATE KEY UPDATE 原子自增，只有行级瞬时锁，不锁表。
  - file_count 是「分配序号」不是物理文件数：秒传不取号、删文件或 move 失败
    都不会回退，允许多几个（tolerance 高），不影响分片目的。
  - 其他环境需手动执行上面的 DDL。
 */
class V3UploadShardModel extends Model
{
    protected $table = 'ao_v3_upload_shard';

    /** 每个分片目录容纳的文件数上限：1~1000 → 1000，1001~2000 → 2000 */
    public const FILES_PER_SEGMENT = 1000;

    /**
     * 原子分配一个当日序号（无事务、无锁表）
     *
     * 用 LAST_INSERT_ID(expr) 把「本次分配到的序号」写入当前连接的会话变量：
     *   - 新行：VALUES 里 LAST_INSERT_ID(1) 令会话值为 1，序号为 1；
     *   - 已存在：ON DUPLICATE KEY UPDATE 里 LAST_INSERT_ID(file_count + 1)
     *     令会话值为自增后的序号。
     * 紧随其后的 SELECT LAST_INSERT_ID() 取回本连接刚分配的值，并发下互不干扰。
     *
     * @param int    $bucketId ao_v3_storage.id
     * @param string $day      YYYYMMDD
     * @return int 本次分配到的序号（>=1）
     */
    public static function allocate(int $bucketId, string $day): int
    {
        if ($bucketId <= 0 || $day === '') {
            return 1;
        }
        Db::execute(
            'INSERT INTO ao_v3_upload_shard (bucket_id, day, file_count) '
            . 'VALUES (?, ?, LAST_INSERT_ID(1)) '
            . 'ON DUPLICATE KEY UPDATE file_count = LAST_INSERT_ID(file_count + 1), update_time = NOW()',
            [$bucketId, $day]
        );
        $row = Db::query('SELECT LAST_INSERT_ID() AS seq');
        $seq = (int)($row[0]['seq'] ?? 0);
        return $seq > 0 ? $seq : 1;
    }

    /**
     * 序号 → 分片目录名：1~1000→1000，1001~2000→2000，以此类推
     */
    public static function segmentOf(int $seq): int
    {
        if ($seq <= 0) {
            return self::FILES_PER_SEGMENT;
        }
        return intdiv($seq - 1, self::FILES_PER_SEGMENT) * self::FILES_PER_SEGMENT + self::FILES_PER_SEGMENT;
    }
}
