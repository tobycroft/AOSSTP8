<?php

namespace app\v1\captcha\utils;

/**
 * 纯 PHP GIF 动画编码器。
 * 输入多个 GD 生成的 GIF 图片字符串与逐帧延迟 (1/100 秒)，
 * 合并为循环播放的动画 GIF。
 */
class GifEncoder
{
    public function encode(array $frames, array $delays): string
    {
        if (count($frames) === 0) {
            return '';
        }
        $first = $frames[0];
        if (strlen($first) < 13) {
            return $first;
        }

        $out = 'GIF89a';
        $out .= substr($first, 6, 7);

        $packed = ord($first[10]);
        $hasGlobal = ($packed & 0x80) === 0x80;
        $colorTableSize = 2 << ($packed & 0x07);
        $offset = 13;
        if ($hasGlobal) {
            $out .= substr($first, 13, $colorTableSize * 3);
            $offset += $colorTableSize * 3;
        }

        $out .= "\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00";

        foreach ($frames as $idx => $frame) {
            $imageBlock = $this->extractImageBlock($frame);
            if ($imageBlock === '') {
                continue;
            }
            $delay = (int) max(1, $delays[$idx] ?? 100);
            $delayBytes = pack('v', $delay);
            $out .= "\x21\xF9\x04\x00" . $delayBytes . "\x00\x00";
            $out .= $imageBlock;
        }

        $out .= ';';
        return $out;
    }

    /**
     * 从单张 GIF 中提取 image descriptor + local color table + LZW image data。
     * 同时把 local color table 保留（image descriptor 的 packed byte 不做修改）。
     */
    protected function extractImageBlock(string $frame): string
    {
        $len = strlen($frame);
        if ($len < 13) {
            return '';
        }

        $packed = ord($frame[10]);
        $hasGlobal = ($packed & 0x80) === 0x80;
        $colorTableSize = 2 << ($packed & 0x07);
        $offset = 13;
        if ($hasGlobal) {
            $offset += $colorTableSize * 3;
        }

        $step = 0;
        while ($offset < $len && $step < 1000) {
            $step++;
            $byte = $frame[$offset];
            if ($byte === '!') {
                $offset += 2;
                if ($offset >= $len) {
                    break;
                }
                $blockSize = ord($frame[$offset]);
                $inner = 0;
                while ($blockSize !== 0 && $offset < $len && $inner < 1000) {
                    $inner++;
                    $offset += 1 + $blockSize;
                    if ($offset >= $len) {
                        break 2;
                    }
                    $blockSize = ord($frame[$offset]);
                }
                $offset += 1;
                continue;
            }
            if ($byte === ',') {
                $start = $offset;
                $offset += 10;
                if ($offset >= $len) {
                    break;
                }
                $localPacked = ord($frame[$offset - 1]);
                if ($localPacked & 0x80) {
                    $localColorSize = 2 << ($localPacked & 0x07);
                    $offset += $localColorSize * 3;
                }
                $offset += 1;
                $inner = 0;
                while ($offset < $len && $inner < 100000) {
                    $inner++;
                    $blockSize = ord($frame[$offset]);
                    if ($blockSize === 0) {
                        $offset += 1;
                        break;
                    }
                    $offset += 1 + $blockSize;
                }
                return substr($frame, $start, $offset - $start);
            }
            if ($byte === ';') {
                break;
            }
            $offset++;
        }
        return '';
    }
}