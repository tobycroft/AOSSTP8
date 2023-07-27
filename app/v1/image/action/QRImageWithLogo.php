<?php

namespace app\v1\image\action;

use chillerlan\QRCode\Output\QRImage;

class QRImageWithLogo extends QRImage
{

    /**
     * @param string|null $file
     * @param string|null $logo
     *
     * @return string
     * @throws \chillerlan\QRCode\Output\QRCodeOutputException
     */
    public function dump(string $file = null, string $logo = null): string
    {
        // set returnResource to true to skip further processing for now
        $this->options->returnResource = true;

        // of course you could accept other formats too (such as resource or Imagick)
        // i'm not checking for the file type either for simplicity reasons (assuming PNG)
//        $logo = file_get_contents($logo);
//        if (!is_file($logo) || !is_readable($logo)) {
//            throw new QRCodeOutputException('invalid logo');
//        }

        // there's no need to save the result of dump() into $this->image here
        parent::dump($file);
        $im = file_get_contents($logo);
        $im = imagecreatefromstring($im);
        // get logo image size
        $w = imagesx($im);
        $h = imagesy($im);


        // set new logo size, leave a border of 1 module (no proportional resize/centering)
        $lw = $this->matrix->size() * 1.5;
        $lh = $this->matrix->size() * 1.5;

        // get the qrcode size
        $ql = $this->matrix->size() * $this->options->scale;
        // scale the logo and copy it over. done!
        imagecopyresampled($this->image, $im, ($ql - $lw) / 2, ($ql - $lh) / 2, 0, 0, $lw, $lh, $w, $h);
//        imagecopymerge($this->image, $im, ($ql - $lw) / 2, ($ql - $lh) / 2, 0, 0, $lw, $lh, 75);
        $imageData = $this->dumpImage();

        if ($file !== null) {
            $this->saveToFile($imageData, $file);
        }

        if ($this->options->imageBase64) {
            $imageData = $this->toBase64DataURI($imageData, 'image/' . $this->options->outputType);
        }

        return $imageData;
    }

}