<?php

namespace app\v1\rcon\action;

class ShowPlayerAction
{


    public static function input($rcon_output)
    {
        $arr = [];
        $lines = explode("\n", $rcon_output);
        $key = explode(',', $lines[0]);
        foreach ($lines as $index => $value) {
            if ($index > 0) {
                $temp = explode(',', $value);
                if (count($temp) < 1) {
                    break;
                }
                $arr[] = [
                    $key[0] => $temp[0],
                    $key[1] => $temp[1],
                    $key[2] => $temp[2],
                ];
            }
        }
        return $arr;
    }
}