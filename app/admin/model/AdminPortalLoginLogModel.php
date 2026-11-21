<?php

namespace app\admin\model;

use think\Model;

/**
 * 门户用户登录记录（ao_login_log）
 *
 * 与门户站共用同一张表：success=1 为成功记录，success=0 为失败记录，
 * 失败记录额外保存尝试的密码与失败原因，便于总后台审计。
 */
class AdminPortalLoginLogModel extends Model
{
    protected $table = 'ao_login_log';
}
