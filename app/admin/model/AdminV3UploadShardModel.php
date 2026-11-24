<?php

namespace app\admin\model;

use think\Model;

/**
 * 后台 V3 上传分片计数：ao_v3_upload_shard
 *
 * 每个 Bucket + 日期一行，记录当日已分配的上传序号；前台据此把文件分摊到
 * upload/{bucket}/{YYYYMMDD}/{1000|2000|...}/ 子目录。后台只读查看，不提供编辑。
 */
class AdminV3UploadShardModel extends Model
{
    protected $table = 'ao_v3_upload_shard';
}
