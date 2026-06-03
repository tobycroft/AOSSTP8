<?php

namespace app\v1\captcha\utils;

class Captcha extends \think\captcha\Captcha
{

    private $session = null;

    public $key;
    public $hash;
    public $question;
    protected $im;

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

    public function create($config = null): array|\think\Response
    {
        if ($config) {
            $this->config($config);
        }

        $generator = $this->generate();
        $text = $generator['value'];

        $imageW = $this->imageW;
        $imageH = $this->imageH;
        if (!$imageW) {
            $imageW = $this->length * $this->fontSize * 1.5 + $this->fontSize * 1.5;
        }
        if (!$imageH) {
            $imageH = $this->fontSize * 2.5;
        }

        $this->im = imagecreatetruecolor((int) $imageW, (int) $imageH);
        $color = imagecolorallocate($this->im, $this->bg[0], $this->bg[1], $this->bg[2]);
        imagefill($this->im, 0, 0, $color);

        if ($this->useImgBg) {
            $this->background();
        }

        if ($this->useNoise) {
            // 绘杂点
            $this->writeNoise();
        }
        if ($this->useCurve) {
            // 绘干扰线
            $this->writeCurve();
        }

        // 绘文字
        $box = imagettfbbox($this->fontSize, 0, $this->getFontttf(), $text);
        $x = intval(($imageW - $box[0] - $box[2]) / 2);
        $y = intval(($imageH - $box[1] - $box[7]) / 2);

        if (!$this->math) {
            for ($i = 0; $i < $this->length; $i++) {
                $x = $i === 0 ? $x : $x + $this->fontSize * 1.2;
                $angle = $this->math ? 0 : mt_rand(-40, 40);

                imagettftext($this->im, (int) $this->fontSize, $angle, (int) $x, (int) $y, $this->color, $this->getFontttf(), $text[$i]);
            }
        } else {
            imagettftext($this->im, (int) $this->fontSize, 0, (int) $x, (int) $y, $this->color, $this->getFontttf(), $text);
        }

        ob_start();
        // 输出图像
        imagepng($this->im);
        $content = ob_get_clean();

        // API调用模式
        if ($this->api) {
            return [
                'code' => implode('', is_array($text) ? $text : str_split((string) $text)),
                'img'  => 'data:image/png;base64,' . base64_encode($content),
            ];
        }
        // 输出验证码图片
        $response = response($content, 200, ['Content-Length' => strlen($content)])
            ->contentType('image/png');

        return $response;
    }
}