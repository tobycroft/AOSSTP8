<?php

namespace app\v1\rcon\action;

class ShowPlayerAction
{
    public function __construct($rcon_output)
    {
        $lines = explode("\n", $rcon_output);

        $array = [];
        $key = explode(',', $lines[0]);

        foreach ($lines as $index => $value) {
            $temp = explode(',', $value);
            if ($index > 0) {
                $array[] = [
                    $key[0] => $temp[0],
                    $key[1] => $temp[1],
                    $key[2] => $temp[2],
                ];
            }
        }
        return $array;
    }
}