<?php

namespace app\v1\image\action;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Logo\Logo;

class QRImageWithLogo
{

    public static function generate($data, $logoPath = null, $size = 400, $margin = 15)
    {
        $qrCode = new QrCode(
            data: $data,
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: $size,
            margin: $margin,
            foregroundColor: new Color(0, 0, 0),
            backgroundColor: new Color(255, 255, 255)
        );
        
        $logo = null;
        if ($logoPath) {
            $logo = new Logo(
                path: $logoPath,
                resizeToWidth: 150,
                resizeToHeight: 150
            );
        }
        
        $writer = new PngWriter();
        $result = $writer->write($qrCode, $logo);
        
        return $result->getString();
    }

}