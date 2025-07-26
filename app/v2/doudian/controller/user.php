<?php

namespace app\v2\doudian\controller;

use app\v1\captcha\model\DoudianUserModel;
use Input;

class user extends index
{
    /*
     * CREATE TABLE `ao_doudian_user` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `uid` bigint unsigned DEFAULT '0' COMMENT '用户抖店id飞书id',
  `screen_name` varchar(255) DEFAULT NULL COMMENT '一般是用户抖音名称',
  `avatar_url` varchar(255) DEFAULT NULL COMMENT '一般是用户抖音头像',
  `is_black` tinyint(1) DEFAULT NULL COMMENT '是否是黑名单',
  `change_date` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `date` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='抖店用户头像等';
     */
    public function add()
    {
        $model = DoudianUserModel::create([
            ['uid', '=', Input::PostInt('uid')],
            ['screen_name', '=', Input::Post('screen_name')],
            ['avatar_url', '=', Input::Post('avatar_url')],
            ['is_black', '=', Input::PostBool('is_black', 0)],
        ]);
        if ($model) {
            \Ret::Success();
        } else {
            \Ret::Fail(500, null, '添加失败');
        }
    }

    public function edit()
    {

    }

    public function del()
    {

    }
}