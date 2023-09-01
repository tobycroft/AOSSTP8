<?php

namespace app\v1\captcha\utils;

class Captcha extends \think\captcha\Captcha
{

    private $session = null;

    public $key;
    public $hash;
    public $question;

    protected function generate(): array
    {
        $bag = '';

        if ($this->math) {
            $this->useZh = false;
            $this->length = 5;

            $x = random_int(10, 30);
            $y = random_int(1, 9);
            $bag = "{$x} + {$y} = ";
            $key = $x + $y;
            $key .= '';
        } else {
            if ($this->useZh) {
                $characters = preg_split('/(?<!^)(?!$)/u', $this->zhSet);
            } else {
                $characters = str_split($this->codeSet);
            }

            for ($i = 0; $i < $this->length; $i++) {
                $bag .= $characters[random_int(0, count($characters) - 1)];
            }

            $key = mb_strtolower($bag, 'UTF-8');
        }

        $hash = password_hash($key, PASSWORD_BCRYPT, ['cost' => 10]);

        $this->key = $key;
        $this->hash = $hash;
        $this->question = $bag;

        return [
            'value' => $bag,
            'key' => $hash,
        ];
    }
}