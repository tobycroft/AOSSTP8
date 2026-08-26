<?php

namespace app\admin\utils;

use tobycroft\captcha\Captcha as TobycroftCaptcha;

class Captcha extends TobycroftCaptcha
{
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

    /**
     * 输出 GIF 验证码
     */
    public function create(?string $config = null)
    {
        $this->configure($config);

        $generator = $this->generate();

        $this->imageW || $this->imageW = $this->length * $this->fontSize * 1.5 + $this->length * $this->fontSize / 2;
        $this->imageH || $this->imageH = $this->fontSize * 2.5;

        $this->imageW = (int) $this->imageW;
        $this->imageH = (int) $this->imageH;

        $this->im = imagecreate($this->imageW, $this->imageH);
        imagecolorallocatealpha($this->im, $this->bg[0], $this->bg[1], $this->bg[2], $this->alpha);
        $this->color = imagecolorallocate($this->im, random_int(1, 150), random_int(1, 150), random_int(1, 150));

        $ttfPath = __DIR__ . '/../../vendor/tobycroft/think-captcha/assets/' . ($this->useZh ? 'zhttfs' : 'ttfs') . '/';

        if (empty($this->fontttf)) {
            $dir = dir($ttfPath);
            $ttfs = [];
            while (false !== ($file = $dir->read())) {
                if (substr($file, -4) === '.ttf' || substr($file, -4) === '.otf') {
                    $ttfs[] = $file;
                }
            }
            $dir->close();
            $this->fontttf = $ttfs[array_rand($ttfs)];
        }

        $fontttf = $ttfPath . $this->fontttf;

        foreach ($this->interfere as $type => $method) {
            if ($this->$type) {
                $this->$method();
            }
        }

        $text = $this->useZh ? preg_split('/(?<!^)(?!$)/u', $generator['value']) : str_split($generator['value']);

        foreach ($text as $index => $char) {
            $x = $this->fontSize * ($index + 1) * ($this->math ? 1 : 1.5);
            $y = $this->fontSize + random_int(10, 20);
            $angle = $this->math ? 0 : random_int(-40, 40);
            imagettftext($this->im, (int) $this->fontSize, $angle, (int) $x, (int) $y, $this->color, $fontttf, $char);
        }

        ob_start();
        imagegif($this->im);
        $content = ob_get_clean();
        $this->im = null;

        return response($content, 200, ['Content-Length' => strlen($content)])->contentType('image/gif');
    }
}