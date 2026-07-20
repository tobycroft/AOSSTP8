<?php

namespace app\v1\captcha\utils;

class SlideCaptcha
{
    public int $bgWidth = 300;
    public int $bgHeight = 150;
    public int $blockSize = 40;
    public int $radius = 9;
    public int $tolerance = 4;

    public int $x;
    public int $y;
    public string $hash;

    protected int $padTop;
    protected int $padRight;
    protected int $maskW;
    protected int $maskH;

    public function __construct(array $config = [])
    {
        foreach ($config as $key => $val) {
            if (property_exists($this, $key)) {
                $this->{$key} = $val;
            }
        }
        $s = (int) ($this->blockSize / 4);
        $this->padTop = max(0, 2 * $this->radius - $s);
        $this->padRight = $s + $this->radius;
        $this->maskW = $this->blockSize + $this->padRight;
        $this->maskH = $this->blockSize + $this->padTop;
    }

    public function generate(): array
    {
        $bg = $this->createBackground();

        $minX = $this->blockSize + 10;
        $maxX = $this->bgWidth - $this->blockSize - 10;
        $this->x = random_int($minX, $maxX);
        $this->y = random_int($this->padTop + 2, $this->bgHeight - $this->blockSize - 2);

        $block = $this->createBlock($bg);
        $this->punchHole($bg);

        ob_start();
        imagepng($bg);
        $bgData = ob_get_clean();

        ob_start();
        imagepng($block);
        $blockData = ob_get_clean();

        $this->hash = password_hash((string) $this->x, PASSWORD_BCRYPT, ['cost' => 10]);

        return [
            'bg' => 'data:image/png;base64,' . base64_encode($bgData),
            'block' => 'data:image/png;base64,' . base64_encode($blockData),
            'y' => $this->y - $this->padTop,
            'y_origin' => $this->y,
            'pad_top' => $this->padTop,
            'bg_width' => $this->bgWidth,
            'bg_height' => $this->bgHeight,
            'block_size' => $this->blockSize,
        ];
    }

    public function check(int $x): bool
    {
        return abs($x - $this->x) <= $this->tolerance;
    }

    protected function createBackground()
    {
        $im = imagecreatetruecolor($this->bgWidth, $this->bgHeight);

        $r = random_int(80, 180);
        $g = random_int(80, 180);
        $b = random_int(80, 180);
        $bgColor = imagecolorallocate($im, $r, $g, $b);
        imagefill($im, 0, 0, $bgColor);

        for ($i = 0; $i < 30; $i++) {
            $color = imagecolorallocate($im, random_int(0, 255), random_int(0, 255), random_int(0, 255));
            $cx = random_int(0, $this->bgWidth);
            $cy = random_int(0, $this->bgHeight);
            $size = random_int(3, 25);
            imagefilledellipse($im, $cx, $cy, $size, $size, $color);
        }

        for ($i = 0; $i < 8; $i++) {
            $color = imagecolorallocate($im, random_int(0, 255), random_int(0, 255), random_int(0, 255));
            imageline($im,
                random_int(0, $this->bgWidth), random_int(0, $this->bgHeight),
                random_int(0, $this->bgWidth), random_int(0, $this->bgHeight),
                $color
            );
        }

        for ($i = 0; $i < 10; $i++) {
            $color = imagecolorallocate($im, random_int(50, 200), random_int(50, 200), random_int(50, 200));
            imagefilledrectangle($im,
                random_int(0, $this->bgWidth - 30), random_int(0, $this->bgHeight - 20),
                random_int(10, 40), random_int(5, 25),
                $color
            );
        }

        return $im;
    }

    protected function getShapeMask(): array
    {
        $size = $this->blockSize;
        $r = $this->radius;
        $s = (int) ($size / 4);
        $padTop = $this->padTop;
        $padRight = $this->padRight;
        $w = $this->maskW;
        $h = $this->maskH;
        $r2 = $r * $r;

        $cxTop = $s;
        $cyTop = $s - $r;
        $cxRight = $size + $s;
        $cyRight = $s - $r;

        $mask = array_fill(0, $h, array_fill(0, $w, 0));

        for ($maskY = 0; $maskY < $h; $maskY++) {
            $localY = $maskY - $padTop;
            for ($maskX = 0; $maskX < $w; $maskX++) {
                $localX = $maskX;
                $in = 0;
                if ($localX >= 0 && $localX <= $size && $localY >= 0 && $localY <= $size) {
                    $in = 1;
                }
                $dx = $localX - $cxTop;
                $dy = $localY - $cyTop;
                if ($dx * $dx + $dy * $dy <= $r2) {
                    $in = 1;
                }
                $dx = $localX - $cxRight;
                $dy = $localY - $cyRight;
                if ($dx * $dx + $dy * $dy <= $r2) {
                    $in = 1;
                }
                $mask[$maskY][$maskX] = $in;
            }
        }
        return $mask;
    }

    protected function punchHole($bg): void
    {
        $w = $this->maskW;
        $h = $this->maskH;
        $mask = $this->getShapeMask();

        $startX = $this->x;
        $startY = $this->y - $this->padTop;

        for ($maskY = 0; $maskY < $h; $maskY++) {
            $by = $startY + $maskY;
            if ($by < 0 || $by >= $this->bgHeight) {
                continue;
            }
            for ($maskX = 0; $maskX < $w; $maskX++) {
                if (empty($mask[$maskY][$maskX])) {
                    continue;
                }
                $bx = $startX + $maskX;
                if ($bx < 0 || $bx >= $this->bgWidth) {
                    continue;
                }
                imagesetpixel($bg, $bx, $by, imagecolorallocatealpha($bg, 0, 0, 0, 127));
            }
        }
        imagesavealpha($bg, true);

        for ($maskY = 0; $maskY < $h; $maskY++) {
            $by = $startY + $maskY;
            if ($by < 0 || $by >= $this->bgHeight) {
                continue;
            }
            for ($maskX = 0; $maskX < $w; $maskX++) {
                if (empty($mask[$maskY][$maskX])) {
                    continue;
                }
                $bx = $startX + $maskX;
                if ($bx < 0 || $bx >= $this->bgWidth) {
                    continue;
                }
                $isEdge = false;
                foreach ([-1, 1] as $dy) {
                    $nmy = $maskY + $dy;
                    if ($nmy < 0 || $nmy >= $h || empty($mask[$nmy][$maskX])) {
                        $isEdge = true;
                        break;
                    }
                }
                if (!$isEdge) {
                    foreach ([-1, 1] as $dx) {
                        $nmx = $maskX + $dx;
                        if ($nmx < 0 || $nmx >= $w || empty($mask[$maskY][$nmx])) {
                            $isEdge = true;
                            break;
                        }
                    }
                }
                if ($isEdge) {
                    imagesetpixel($bg, $bx, $by, imagecolorallocate($bg, 20, 20, 20));
                }
            }
        }
    }

    protected function createBlock($bg)
    {
        $w = $this->maskW;
        $h = $this->maskH;
        $mask = $this->getShapeMask();

        $block = imagecreatetruecolor($w, $h);
        imagesavealpha($block, true);
        $transparent = imagecolorallocatealpha($block, 0, 0, 0, 127);
        imagefill($block, 0, 0, $transparent);

        $startX = $this->x;
        $startY = $this->y - $this->padTop;

        for ($maskY = 0; $maskY < $h; $maskY++) {
            $by = $startY + $maskY;
            if ($by < 0 || $by >= $this->bgHeight) {
                continue;
            }
            for ($maskX = 0; $maskX < $w; $maskX++) {
                if (empty($mask[$maskY][$maskX])) {
                    continue;
                }
                $bx = $startX + $maskX;
                if ($bx < 0 || $bx >= $this->bgWidth) {
                    continue;
                }
                $color = imagecolorat($bg, $bx, $by);
                imagesetpixel($block, $maskX, $maskY, $color);
            }
        }

        for ($maskY = 0; $maskY < $h; $maskY++) {
            for ($maskX = 0; $maskX < $w; $maskX++) {
                if (empty($mask[$maskY][$maskX])) {
                    continue;
                }
                $isEdge = false;
                foreach ([-1, 1] as $dy) {
                    $nmy = $maskY + $dy;
                    if ($nmy < 0 || $nmy >= $h || empty($mask[$nmy][$maskX])) {
                        $isEdge = true;
                        break;
                    }
                }
                if (!$isEdge) {
                    foreach ([-1, 1] as $dx) {
                        $nmx = $maskX + $dx;
                        if ($nmx < 0 || $nmx >= $w || empty($mask[$maskY][$nmx])) {
                            $isEdge = true;
                            break;
                        }
                    }
                }
                if ($isEdge) {
                    imagesetpixel($block, $maskX, $maskY, imagecolorallocate($block, 235, 235, 235));
                }
            }
        }

        return $block;
    }
}