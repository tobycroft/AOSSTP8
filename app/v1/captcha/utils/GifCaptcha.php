<?php

namespace app\v1\captcha\utils;

use think\Response;

class GifCaptcha
{
    public $key;
    public $hash;
    public $question;

    protected string $codeSet = '2345678abcdefhijkmnpqrstuvwxyzABCDEFGHJKLMNPQRTUVWXY';
    protected int $length = 4;
    protected int $fontSize = 25;
    protected array $bg = [243, 251, 254];
    protected int $imageW = 0;
    protected int $imageH = 0;
    protected int $frameDelay = 100;
    protected bool $useNoise = true;
    protected bool $useCurve = true;

    public function __construct(array $config = [])
    {
        foreach ($config as $key => $val) {
            if (property_exists($this, $key)) {
                $this->{$key} = $val;
            }
        }
    }

    public function create()
    {
        $this->generate();

        $this->imageW || $this->imageW = (int) ($this->length * $this->fontSize * 1.8);
        $this->imageH || $this->imageH = (int) ($this->fontSize * 2.5);

        $ttfPath = dirname(__DIR__, 4) . '/vendor/tobycroft/think-captcha/assets/ttfs/';
        $ttfs = [];
        $dir = dir($ttfPath);
        while (false !== ($file = $dir->read())) {
            if (substr($file, -4) === '.ttf') {
                $ttfs[] = $ttfPath . $file;
            }
        }
        $dir->close();

        $chars = str_split($this->question);
        $totalFrames = $this->length + 1;
        $frames = [];
        $delays = [];

        for ($frame = 0; $frame < $totalFrames; $frame++) {
            $im = imagecreate($this->imageW, $this->imageH);
            imagecolorallocate($im, $this->bg[0], $this->bg[1], $this->bg[2]);
            $color = imagecolorallocate($im, random_int(1, 150), random_int(1, 150), random_int(1, 150));

            if ($this->useNoise) {
                $this->writeNoise($im);
            }
            if ($this->useCurve) {
                $this->writeCurve($im, $color);
            }

            $fontttf = $ttfs[array_rand($ttfs)];

            foreach ($chars as $index => $char) {
                if ($index !== $frame) {
                    continue;
                }
                $x = (int) ($this->fontSize * ($index + 1) * 1.5);
                $y = (int) ($this->fontSize + random_int(10, 20));
                $angle = random_int(-40, 40);
                imagettftext($im, $this->fontSize, $angle, $x, $y, $color, $fontttf, $char);
            }

            ob_start();
            imagegif($im);
            $frames[] = ob_get_clean();
            $delays[] = $this->frameDelay;
        }

        $gif = (new GifEncoder())->encode($frames, $delays);
        return Response::create($gif, 'html', 200)->header(['Content-Length' => strlen($gif)])->contentType('image/gif');
    }

    protected function generate(): void
    {
        $characters = str_split($this->codeSet);
        $bag = '';
        for ($i = 0; $i < $this->length; $i++) {
            $bag .= $characters[random_int(0, count($characters) - 1)];
        }
        $this->question = $bag;
        $this->key = mb_strtolower($bag, 'UTF-8');
        $this->hash = password_hash($this->key, PASSWORD_BCRYPT, ['cost' => 10]);
    }

    protected function writeNoise($im): void
    {
        $codeSet = '2345678abcdefhijkmnpqrstuvwxyz';
        for ($i = 0; $i < 10; $i++) {
            $noiseColor = imagecolorallocate($im, random_int(150, 225), random_int(150, 225), random_int(150, 225));
            for ($j = 0; $j < 5; $j++) {
                imagestring($im, 5, random_int(-10, $this->imageW), random_int(-10, $this->imageH), $codeSet[random_int(0, 29)], $noiseColor);
            }
        }
    }

    protected function writeCurve($im, $color): void
    {
        $A = random_int(1, (int) ($this->imageH / 2));
        $b = random_int((int) (-$this->imageH / 4), (int) ($this->imageH / 4));
        $f = random_int((int) (-$this->imageH / 4), (int) ($this->imageH / 4));
        $T = random_int($this->imageH, $this->imageW * 2);
        $w = (2 * M_PI) / $T;
        $px1 = 0;
        $px2 = random_int((int) ($this->imageW / 2), (int) ($this->imageW * 0.8));
        for ($px = $px1; $px <= $px2; $px++) {
            $py = $A * sin($w * $px + $f) + $b + $this->imageH / 2;
            $i = (int) ($this->fontSize / 5);
            while ($i > 0) {
                imagesetpixel($im, (int) ($px + $i), (int) ($py + $i), $color);
                $i--;
            }
        }
    }
}