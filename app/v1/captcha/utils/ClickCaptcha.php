<?php

namespace app\v1\captcha\utils;

class ClickCaptcha
{
    public int $bgWidth = 300;
    public int $bgHeight = 200;
    public int $fontSize = 28;
    public int $tolerance = 35;

    public array $targets = [];
    public string $hash;
    protected string $fontPath;

    // 常用汉字库
    protected array $charPool = [
        '天', '地', '人', '日', '月', '水', '火', '山', '石', '木',
        '金', '土', '风', '云', '雨', '雪', '花', '草', '鸟', '鱼',
        '马', '牛', '羊', '龙', '虎', '门', '车', '船', '书', '笔',
        '心', '手', '口', '目', '耳', '足', '田', '禾', '米', '果',
        '星', '光', '电', '雷', '河', '海', '湖', '林', '森', '叶',
        '春', '夏', '秋', '冬', '东', '西', '南', '北', '中', '国',
        '大', '小', '多', '少', '上', '下', '左', '右', '前', '后',
        '红', '黄', '蓝', '绿', '白', '黑', '高', '低', '长', '短',
        '男', '女', '老', '少', '学', '生', '师', '医', '乐', '舞',
        '歌', '画', '文', '字', '语', '数', '科', '技', '网', '信',
    ];

    public function __construct(array $config = [])
    {
        foreach ($config as $key => $val) {
            if (property_exists($this, $key)) {
                $this->{$key} = $val;
            }
        }
        $this->fontPath = $this->detectFont();
    }

    protected function detectFont(): string
    {
        // 1. 项目内置 MiSans 字体（优先）
        $projectFont = public_path() . 'static/misans/misans.ttf';
        if (@file_exists($projectFont)) {
            return $projectFont;
        }

        // 2. 备用：captcha 目录下的字体
        $fallbackFont = public_path() . 'static/captcha/font.ttf';
        if (@file_exists($fallbackFont)) {
            return $fallbackFont;
        }

        // 2. 检查系统常见字体路径
        $candidates = [
            '/usr/share/fonts/truetype/wqy/wqy-microhei.ttc',
            '/usr/share/fonts/truetype/droid/DroidSansFallbackFull.ttf',
            '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc',
            '/usr/share/fonts/truetype/noto/NotoSansCJK-Regular.ttc',
            '/usr/share/fonts/truetype/arphic/uming.ttc',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/System/Library/Fonts/STHeiti Medium.ttc',
            '/System/Library/Fonts/PingFang.ttc',
            '/System/Library/Fonts/Supplemental/Songti.ttc',
        ];
        foreach ($candidates as $path) {
            if (@file_exists($path)) {
                return $path;
            }
        }

        // 3. 尝试自动下载字体到项目目录
        if ($this->tryDownloadFont($projectFont)) {
            return $projectFont;
        }

        throw new \RuntimeException(
            'No Chinese font found. Please install fonts on your server:' . "\n" .
            '  Ubuntu/Debian: sudo apt install fonts-wqy-microhei -y' . "\n" .
            '  CentOS/RHEL:   sudo yum install wqy-microhei-fonts -y' . "\n" .
            'Or manually download a .ttf font to: ' . $projectFont
        );
    }

    protected function tryDownloadFont(string $targetPath): bool
    {
        $fontUrls = [
            'https://github.com/google/fonts/raw/main/ofl/notosanssc/NotoSansSC%5Bwght%5D.ttf',
            'https://raw.githubusercontent.com/notofonts/noto-cjk/main/Sans/OTF/SimplifiedChinese/NotoSansCJKsc-Regular.otf',
        ];

        $dir = dirname($targetPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $ctx = stream_context_create([
            'http' => [
                'timeout' => 15,
                'follow_location' => true,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);

        foreach ($fontUrls as $url) {
            $data = @file_get_contents($url, false, $ctx);
            if ($data && strlen($data) > 50000) {
                @file_put_contents($targetPath, $data);
                if (@file_exists($targetPath) && filesize($targetPath) > 50000) {
                    return true;
                }
            }
        }

        return false;
    }

    public function generate(): array
    {
        $bg = $this->createBackground();

        // 随机选择 5-6 个显示字符（含 2-3 个目标字符）
        $numTargets = random_int(2, 3);
        $numDistractors = random_int(3, 4);

        // 从字库中随机选字
        $shuffled = $this->charPool;
        shuffle($shuffled);
        $targetChars = array_slice($shuffled, 0, $numTargets);
        $distractorChars = array_slice($shuffled, $numTargets, $numDistractors);
        $allChars = array_merge($targetChars, $distractorChars);
        shuffle($allChars);

        // 在画布上放置文字，记录位置
        $this->targets = [];
        $positions = $this->placeCharacters($bg, $allChars, $targetChars);
        
        // 按提示文字的顺序重新排列目标位置
        $this->targets = [];
        foreach ($targetChars as $char) {
            foreach ($positions as $pos) {
                if ($pos['char'] === $char) {
                    $this->targets[] = ['char' => $pos['char'], 'x' => $pos['x'], 'y' => $pos['y']];
                    break;
                }
            }
        }

        // 绘制干扰元素
        $this->addNoise($bg);

        ob_start();
        imagepng($bg);
        $bgData = ob_get_clean();

        $this->hash = password_hash(json_encode($this->targets), PASSWORD_BCRYPT, ['cost' => 10]);

        $tipText = '请依次点击：' . implode('、', $targetChars);

        return [
            'bg' => 'data:image/png;base64,' . base64_encode($bgData),
            'tip' => $tipText,
            'targets_count' => $numTargets,
            'bg_width' => $this->bgWidth,
            'bg_height' => $this->bgHeight,
        ];
    }

    public function check(array $clicks): bool
    {
        if (count($clicks) !== count($this->targets)) {
            return false;
        }

        foreach ($this->targets as $i => $target) {
            $click = $clicks[$i] ?? null;
            if ($click === null) {
                return false;
            }
            $dx = $click['x'] - $target['x'];
            $dy = $click['y'] - $target['y'];
            $dist = sqrt($dx * $dx + $dy * $dy);
            if ($dist > $this->tolerance) {
                return false;
            }
        }
        return true;
    }

    protected function createBackground()
    {
        $im = imagecreatetruecolor($this->bgWidth, $this->bgHeight);

        $r = random_int(220, 250);
        $g = random_int(220, 250);
        $b = random_int(220, 250);
        $bgColor = imagecolorallocate($im, $r, $g, $b);
        imagefill($im, 0, 0, $bgColor);

        return $im;
    }

    protected function placeCharacters($im, array $allChars, array $targetChars): array
    {
        $positions = [];
        $usedRects = [];
        $margin = 20;
        $charSize = $this->fontSize * 1.5;

        foreach ($allChars as $char) {
            $isTarget = in_array($char, $targetChars, true);

            // 先计算这个字符的实际边界框
            $bbox = imagettfbbox($this->fontSize, 0, $this->fontPath, $char);
            $bboxMinX = min($bbox[0], $bbox[2], $bbox[4], $bbox[6]);
            $bboxMaxX = max($bbox[0], $bbox[2], $bbox[4], $bbox[6]);
            $bboxMinY = min($bbox[1], $bbox[3], $bbox[5], $bbox[7]);
            $bboxMaxY = max($bbox[1], $bbox[3], $bbox[5], $bbox[7]);
            $offsetX = ($bboxMinX + $bboxMaxX) / 2;
            $offsetY = ($bboxMinY + $bboxMaxY) / 2;

            $maxAttempts = 50;
            $placed = false;
            for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
                // centerX, centerY 是字符中心点
                $centerX = random_int($margin + (int)$charSize / 2, $this->bgWidth - $margin - (int)$charSize / 2);
                $centerY = random_int($margin + (int)$charSize / 2, $this->bgHeight - $margin - (int)$charSize / 2);

                $rect = [
                    'x1' => $centerX - (int)$charSize / 2,
                    'y1' => $centerY - (int)$charSize / 2,
                    'x2' => $centerX + (int)$charSize / 2,
                    'y2' => $centerY + (int)$charSize / 2,
                ];

                $overlap = false;
                foreach ($usedRects as $used) {
                    if (!($rect['x2'] < $used['x1'] || $rect['x1'] > $used['x2'] ||
                          $rect['y2'] < $used['y1'] || $rect['y1'] > $used['y2'])) {
                        $overlap = true;
                        break;
                    }
                }

                if (!$overlap) {
                    $usedRects[] = $rect;
                    $angle = random_int(-15, 15);
                    $color = $this->randomDarkColor($im);

                    // 根据边界框的偏移反推正确的 cx, cy，确保字符中心在 (centerX, centerY)
                    $cx = (int)($centerX - $offsetX);
                    $cy = (int)($centerY - $offsetY);

                    imagettftext($im, $this->fontSize, $angle, $cx, $cy, $color, $this->fontPath, $char);

                    $posX = $centerX;
                    $posY = $centerY;

                    $positions[] = ['char' => $char, 'x' => $posX, 'y' => $posY, 'is_target' => $isTarget];

                    $placed = true;
                    break;
                }
            }

            if (!$placed) {
                $centerX = random_int($margin + (int)$charSize / 2, $this->bgWidth - $margin - (int)$charSize / 2);
                $centerY = random_int($margin + (int)$charSize / 2, $this->bgHeight - $margin - (int)$charSize / 2);
                
                $angle = random_int(-15, 15);
                $color = $this->randomDarkColor($im);
                $cx = (int)($centerX - $offsetX);
                $cy = (int)($centerY - $offsetY);
                
                imagettftext($im, $this->fontSize, $angle, $cx, $cy, $color, $this->fontPath, $char);
                
                $posX = $centerX;
                $posY = $centerY;
                
                $positions[] = ['char' => $char, 'x' => $posX, 'y' => $posY, 'is_target' => $isTarget];
            }
        }

        return $positions;
    }

    protected function addNoise($im): void
    {
        for ($i = 0; $i < 15; $i++) {
            $color = imagecolorallocate($im, random_int(180, 230), random_int(180, 230), random_int(180, 230));
            $cx = random_int(0, $this->bgWidth);
            $cy = random_int(0, $this->bgHeight);
            $size = random_int(2, 8);
            imagefilledellipse($im, $cx, $cy, $size, $size, $color);
        }

        for ($i = 0; $i < 5; $i++) {
            $color = imagecolorallocate($im, random_int(200, 240), random_int(200, 240), random_int(200, 240));
            imageline($im,
                random_int(0, $this->bgWidth), random_int(0, $this->bgHeight),
                random_int(0, $this->bgWidth), random_int(0, $this->bgHeight),
                $color
            );
        }

        for ($i = 0; $i < 3; $i++) {
            $color = imagecolorallocate($im, random_int(200, 250), random_int(200, 250), random_int(200, 250));
            $x1 = random_int(0, $this->bgWidth - 60);
            $y1 = random_int(0, $this->bgHeight - 40);
            $x2 = $x1 + random_int(20, 60);
            $y2 = $y1 + random_int(10, 40);
            imagefilledrectangle($im, $x1, $y1, $x2, $y2, $color);
        }
    }

    protected function randomDarkColor($im): int
    {
        return imagecolorallocate($im,
            random_int(20, 80),
            random_int(20, 80),
            random_int(20, 80)
        );
    }
}