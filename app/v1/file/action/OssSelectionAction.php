<?php

namespace app\v1\file\action;

use app\v1\file\model\OssAliyunModel;

class OssSelectionAction
{
    public static function App_find_byProc(mixed $proc)
    {
        switch ($proc["oss_type"]) {
            case 'aliyun':
                $oss = OssAliyunModel::where("tag", $proc["oss_tag"])->find();
                if ($oss) {
                    return array_merge($proc, $oss);
                }
                break;

            case 'tencent':
                break;

            default:
                break;
        }
        return $proc;
    }
}