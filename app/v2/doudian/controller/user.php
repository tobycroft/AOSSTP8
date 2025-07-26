<?php

namespace app\v2\doudian\controller;

use app\v2\doudian\model\DoudianUserModel;
use Input;
use Ret;

class user extends index
{
    /*
CREATE TABLE `ao_doudian_user` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `appid` int unsigned DEFAULT '0',
  `uid` bigint unsigned DEFAULT '0' COMMENT '用户抖店id飞书id',
  `screen_name` varchar(255) DEFAULT NULL COMMENT '一般是用户抖音名称',
  `avatar_url` varchar(255) DEFAULT NULL COMMENT '一般是用户抖音头像',
  `is_black` tinyint(1) DEFAULT NULL COMMENT '是否是黑名单',
  `change_date` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `date` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='抖店用户头像等';
     */
    public function auto()
    {
        $model = DoudianUserModel::where('appid', '=', $this->project['appid'])
            ->where('uid', '=', Input::PostInt('uid'))
            ->find();
        if ($model) {
            $model->screen_name = Input::Post('screen_name');
            $model->avatar_url = Input::Post('avatar_url');
            $model->is_black = Input::PostBool('is_black', false);
            $model->save();
        } else {
            $model = new DoudianUserModel();
            $model->appid = $this->project['appid'];
            $model->uid = Input::PostInt('uid');
            $model->screen_name = Input::Post('screen_name');
            $model->avatar_url = Input::Post('avatar_url');
            $model->is_black = Input::PostBool('is_black', false);
            $model->save();
        }
    }

    public function get()
    {
        $uid = Input::PostInt('uid');
        $model = DoudianUserModel::where('appid', '=', $this->project['appid'])
            ->where('uid', '=', $uid)
            ->find();
        if ($model) {
            Ret::Success(
                0,
                [
                    'uid' => $model->uid,
                    'screen_name' => $model->screen_name,
                    'avatar_url' => $model->avatar_url,
                    'is_black' => $model->is_black
                ],
                'User information retrieved successfully'
            );
        }
    }

    public function del()
    {
        $uid=Input::PostInt('uid');
        $model = DoudianUserModel::where('appid', '=', $this->project['appid'])
            ->where('uid', '=', $uid)
            ->find();
        if ($model) {
            $model->delete();
            Ret::Success(0, null, 'User deleted successfully');
        } else {
            Ret::Fail(404, null, 'User not found');
        }
    }
}