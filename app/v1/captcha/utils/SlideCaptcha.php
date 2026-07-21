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

    public $topBulge;
    public $rightBulge;
    public $bottomBulge;
    public $leftBulge;

    protected int $s;
    protected int $padLeft;
    protected int $padRight;
    protected int $padTop;
    protected int $padBottom;
    protected int $maskW;
    protected int $maskH;

    public function __construct(array $config = [])
    {
        foreach ($config as $key => $val) {
            if (property_exists($this, $key)) {
                $this->{$key} = $val;
            }
        }
    }

    public function generate(): array
    {
        $bg = $this->createBackground();

        // 为本次生成随机形状
        $this->topBulge = random_int(-1, 1);
        $this->rightBulge = random_int(-1, 1);
        $this->bottomBulge = random_int(-1, 1);
        $this->leftBulge = random_int(-1, 1);

        $this->s = (int) ($this->blockSize / 4);
        $this->padLeft = $this->leftBulge === 1 ? ($this->s + $this->radius) : 0;
        $this->padRight = $this->rightBulge === 1 ? ($this->s + $this->radius) : 0;
        $this->padTop = $this->topBulge === 1 ? ($this->s + $this->radius) : 0;
        $this->padBottom = $this->bottomBulge === 1 ? ($this->s + $this->radius) : 0;
        $this->maskW = $this->blockSize + $this->padLeft + $this->padRight;
        $this->maskH = $this->blockSize + $this->padTop + $this->padBottom;

        $minX = $this->blockSize + 10;
        $maxX = $this->bgWidth - $this->blockSize - 10 - $this->padRight;
        $this->x = random_int($minX, $maxX);
        $this->y = random_int($this->padTop + 2, $this->bgHeight - $this->blockSize - 2 - $this->padBottom);

        $block = $this->createBlock($bg);
        $this->punchHole($bg);

        // ====== 在背景上绘制1-2个干扰假缺口 ======
        // 假缺口：比真正缺口小，位置在同一行附近，增加 AI 识别难度
        $numDecoys = random_int(1, 2);
        for ($i = 0; $i < $numDecoys; $i++) {
            // 假缺口尺寸 25-32 像素（比40小一些，但仍然明显）
            $decoysize = random_int(25, 32);
            $decoyS = (int)($decoysize / 4);
            $decoyR = (int)($decoysize / 4.5);
            if ($decoyR < 6) $decoyR = 6;

            // 假缺口有自己独立的随机形状
            $decoyTop = random_int(-1, 1);
            $decoyRight = random_int(-1, 1);
            $decoyBot = random_int(-1, 1);
            $decoyLeft = random_int(-1, 1);

            // 计算假缺口在背景上的位置
            $decoypadT = $decoyTop === 1 ? ($decoyS + $decoyR) : 0;
            $decoypadL = $decoyLeft === 1 ? ($decoyS + $decoyR) : 0;
            $decoypadR = $decoyRight === 1 ? ($decoyS + $decoyR) : 0;

            // 假缺口的 Y 位置：与真缺口在同一行（y 值相同或接近）
            $decoyY = $this->y + random_int(-5, 5);
            $decoyY = max($decoypadT + 2, min($decoyY, $this->bgHeight - $decoysize - 2));

            // 假缺口的 X 位置：离真缺口至少 60 像素
            $decoyMinX = $this->blockSize + 5;
            $decoyMaxX = $this->bgWidth - $decoysize - 5 - $decoypadR;
            $decoyX = random_int($decoyMinX, $decoyMaxX);
            // 如果离真缺口太近，换到另一边
            if (abs($decoyX - $this->x) < 60) {
                if ($decoyX < $this->x) {
                    $decoyX = max($decoyMinX, $this->x - 60 - random_int(0, 20));
                } else {
                    $decoyX = min($decoyMaxX, $this->x + 60 + random_int(0, 20));
                }
            }

            // 生成假缺口的形状掩码
            $decoyMask = $this->getShapeMask($decoysize, $decoyR, $decoyS, $decoyTop, $decoyRight, $decoyBot, $decoyLeft);
            // 在背景上绘制假缺口
            $this->drawCustomHole($bg, $decoyMask, $decoyX, $decoyY);
        }

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
            'y' => $this->y,
            'x' => $this->x,
            'pad_top' => $this->padTop,
            'pad_left' => $this->padLeft,
            'block_width' => $this->maskW,
            'block_height' => $this->maskH,
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

    protected function getShapeMask(int $size, int $r, int $s, int $topB, int $rightB, int $botB, int $leftB): array
    {
        $padL = $leftB === 1 ? ($s + $r) : 0;
        $padR = $rightB === 1 ? ($s + $r) : 0;
        $padT = $topB === 1 ? ($s + $r) : 0;
        $padBot = $botB === 1 ? ($s + $r) : 0;
        $w = $size + $padL + $padR;
        $h = $size + $padT + $padBot;
        $r2 = $r * $r;

        $mask = array_fill(0, $h, array_fill(0, $w, 0));

        $topCx = $s + $r;
        $topCy = -$r;
        $botCx = $size / 2;
        $botCy = $size + $r;
        $rightCx = $size + $r;
        $rightCy = $s + $r;
        $leftCx = -$r;
        $leftCy = $s + $r;

        $topCavCx = $s + $r;
        $topCavCy = $r;
        $botCavCx = $size / 2;
        $botCavCy = $size - $r;
        $rightCavCx = $size - $r;
        $rightCavCy = $s + $r;
        $leftCavCx = $r;
        $leftCavCy = $s + $r;

        for ($maskY = 0; $maskY < $h; $maskY++) {
            $localY = $maskY - $padT;
            for ($maskX = 0; $maskX < $w; $maskX++) {
                $localX = $maskX - $padL;

                $inRect = ($localX >= 0 && $localX < $size && $localY >= 0 && $localY < $size);

                $inTopBulge = $topB === 1 ? $this->inCircle($localX, $localY, $topCx, $topCy, $r2) : false;
                $inBotBulge = $botB === 1 ? $this->inCircle($localX, $localY, $botCx, $botCy, $r2) : false;
                $inRightBulge = $rightB === 1 ? $this->inCircle($localX, $localY, $rightCx, $rightCy, $r2) : false;
                $inLeftBulge = $leftB === 1 ? $this->inCircle($localX, $localY, $leftCx, $leftCy, $r2) : false;

                $inTopCav = $topB === -1 ? $this->inCircle($localX, $localY, $topCavCx, $topCavCy, $r2) : false;
                $inBotCav = $botB === -1 ? $this->inCircle($localX, $localY, $botCavCx, $botCavCy, $r2) : false;
                $inRightCav = $rightB === -1 ? $this->inCircle($localX, $localY, $rightCavCx, $rightCavCy, $r2) : false;
                $inLeftCav = $leftB === -1 ? $this->inCircle($localX, $localY, $leftCavCx, $leftCavCy, $r2) : false;

                $in = ($inRect || $inTopBulge || $inBotBulge || $inRightBulge || $inLeftBulge)
                    && !$inTopCav && !$inBotCav && !$inRightCav && !$inLeftCav;

                $mask[$maskY][$maskX] = $in ? 1 : 0;
            }
        }
        return ['mask' => $mask, 'w' => $w, 'h' => $h, 'padL' => $padL, 'padR' => $padR, 'padT' => $padT, 'padBot' => $padBot];
    }

    // 绘制任意形状的空洞到背景上
    protected function drawCustomHole($bg, array $maskData, int $x, int $y): void
    {
        $mask = $maskData['mask'];
        $w = $maskData['w'];
        $h = $maskData['h'];
        $padL = $maskData['padL'];
        $padT = $maskData['padT'];

        $startX = $x - $padL;
        $startY = $y - $padT;

        for ($maskY = 0; $maskY < $h; $maskY++) {
            $by = $startY + $maskY;
            if ($by < 0 || $by >= $this->bgHeight) continue;
            for ($maskX = 0; $maskX < $w; $maskX++) {
                if (empty($mask[$maskY][$maskX])) continue;
                $bx = $startX + $maskX;
                if ($bx < 0 || $bx >= $this->bgWidth) continue;
                imagesetpixel($bg, $bx, $by, imagecolorallocatealpha($bg, 0, 0, 0, 127));
            }
        }
        imagesavealpha($bg, true);

        for ($maskY = 0; $maskY < $h; $maskY++) {
            $by = $startY + $maskY;
            if ($by < 0 || $by >= $this->bgHeight) continue;
            for ($maskX = 0; $maskX < $w; $maskX++) {
                if (empty($mask[$maskY][$maskX])) continue;
                $bx = $startX + $maskX;
                if ($bx < 0 || $bx >= $this->bgWidth) continue;
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

    protected function inCircle($x, $y, $cx, $cy, $r2): bool
    {
        $dx = $x - $cx;
        $dy = $y - $cy;
        return $dx * $dx + $dy * $dy <= $r2;
    }

    protected function punchHole($bg): void
    {
        $maskData = $this->getShapeMask($this->blockSize, $this->radius, $this->s, $this->topBulge, $this->rightBulge, $this->bottomBulge, $this->leftBulge);
        $this->drawCustomHole($bg, $maskData, $this->x, $this->y);
    }

    protected function createBlock($bg)
    {
        $maskData = $this->getShapeMask($this->blockSize, $this->radius, $this->s, $this->topBulge, $this->rightBulge, $this->bottomBulge, $this->leftBulge);
        $mask = $maskData['mask'];
        $w = $maskData['w'];
        $h = $maskData['h'];
        $padL = $maskData['padL'];
        $padT = $maskData['padT'];

        $block = imagecreatetruecolor($w, $h);
        imagesavealpha($block, true);
        $transparent = imagecolorallocatealpha($block, 0, 0, 0, 127);
        imagefill($block, 0, 0, $transparent);

        $startX = $this->x - $padL;
        $startY = $this->y - $padT;

        for ($maskY = 0; $maskY < $h; $maskY++) {
            $by = $startY + $maskY;
            if ($by < 0 || $by >= $this->bgHeight) continue;
            for ($maskX = 0; $maskX < $w; $maskX++) {
                if (empty($mask[$maskY][$maskX])) continue;
                $bx = $startX + $maskX;
                if ($bx < 0 || $bx >= $this->bgWidth) continue;
                $color = imagecolorat($bg, $bx, $by);
                imagesetpixel($block, $maskX, $maskY, $color);
            }
        }

        for ($maskY = 0; $maskY < $h; $maskY++) {
            for ($maskX = 0; $maskX < $w; $maskX++) {
                if (empty($mask[$maskY][$maskX])) continue;
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