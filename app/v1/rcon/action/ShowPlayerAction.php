<?php

namespace app\v1\rcon\action;

class ShowPlayerAction
{

    public array $arr = [];

    public function __construct($rcon_output)
    {
//        $this->arr = [];
        $lines = explode("\n", $rcon_output);
        $key = explode(',', $lines[0]);
        foreach ($lines as $index => $value) {
            $temp = explode(',', $value);
            if ($index > 0) {
                $this->arr[] = [
                    $key[0] => $temp[0],
                    $key[1] => $temp[1],
                    $key[2] => $temp[2],
                ];
            }
        }
        return $this->arr;
    }
}