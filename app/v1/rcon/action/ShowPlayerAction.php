<?php

namespace app\v1\rcon\action;

class ShowPlayerAction
{

    public static function decode($rcon_output)
    {
        $arr = [];
        $lines = explode("\n", $rcon_output);
        if (count($lines) < 1) {
            return false;
        }
        $key = explode(',', $lines[0]);
        foreach ($lines as $index => $value) {
            if ($index > 0) {
                $temp = explode(',', $value);
                if (count($temp) < 3) {
                    return false;
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