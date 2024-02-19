<?php

namespace app\v1\rcon\action;

class ShowPlayerAction
{
    public function __construct($rcon_output)
    {
        $lines = explode("\n", $rcon_output);

        $array = array();

        for ($i = 1; $i < count($lines); $i++) {
            //使用explode函数按逗号分割每一行，得到一个临时的一维数组
            $temp = explode(',', $lines[$i]);
            //将临时数组的元素按照表头的顺序作为键值对，添加到二维数组中
            $array[] = array(
                'name' => $temp[0],
                'playeruid' => $temp[1],
                'steamid' => $temp[2]
            );
        }
        return $array;
    }
}