<?php

namespace App\Support\Receipt;

use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode as Generator;
use chillerlan\QRCode\QROptions;

/**
 * QR code sebagai SVG inline (untuk struk web & cetak lewat browser).
 * Printer thermal tidak memakai ini — ia membuat QR sendiri lewat perintah ESC/POS.
 */
class QrCode
{
    public static function svg(string $data): string
    {
        $options = new QROptions([
            'outputInterface' => QRMarkupSVG::class,
            'outputBase64' => false,
            'svgAddXmlHeader' => false,
            'eccLevel' => 'M',
            'drawLightModules' => false,
            'connectPaths' => true,
            'addQuietzone' => true,
            'quietzoneSize' => 2,
            'markupDark' => '#000',
        ]);

        return (new Generator($options))->render($data);
    }
}
