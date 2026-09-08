<?php
// Lightweight Code 128-B SVG renderer used for printable product labels.

/**
 * Render an ASCII value as a self-contained Code 128-B SVG barcode.
 */
function code128_svg(string $value, int $barHeight = 58, int $moduleWidth = 2): string
{
    $value = trim($value);
    if ($value === '') {
        throw new InvalidArgumentException('Barcode value cannot be empty.');
    }

    $patterns = [
        '212222','222122','222221','121223','121322','131222','122213','122312','132212','221213',
        '221312','231212','112232','122132','122231','113222','123122','123221','223211','221132',
        '221231','213212','223112','312131','311222','321122','321221','312212','322112','322211',
        '212123','212321','232121','111323','131123','131321','112313','132113','132311','211313',
        '231113','231311','112133','112331','132131','113123','113321','133121','313121','211331',
        '231131','213113','213311','213131','311123','311321','331121','312113','312311','332111',
        '314111','221411','431111','111224','111422','121124','121421','141122','141221','112214',
        '112412','122114','122411','142112','142211','241211','221114','413111','241112','134111',
        '111242','121142','121241','114212','124112','124211','411212','421112','421211','212141',
        '214121','412121','111143','111341','131141','114113','114311','411113','411311','113141',
        '114131','311141','411131','211412','211214','211232','2331112',
    ];

    $codes = [104]; // Start Code B.
    $checksum = 104;
    $length = strlen($value);

    for ($i = 0; $i < $length; $i++) {
        $ascii = ord($value[$i]);
        if ($ascii < 32 || $ascii > 126) {
            throw new InvalidArgumentException('Barcode contains unsupported characters.');
        }
        $code = $ascii - 32;
        $codes[] = $code;
        $checksum += $code * ($i + 1);
    }

    $codes[] = $checksum % 103;
    $codes[] = 106; // Stop.

    $quietModules = 10;
    $totalModules = $quietModules * 2;
    foreach ($codes as $code) {
        $totalModules += array_sum(array_map('intval', str_split($patterns[$code])));
    }

    $width = $totalModules * $moduleWidth;
    $height = max(30, $barHeight);
    $x = $quietModules * $moduleWidth;
    $rectangles = [];

    foreach ($codes as $code) {
        $pattern = str_split($patterns[$code]);
        foreach ($pattern as $index => $moduleCount) {
            $segmentWidth = ((int)$moduleCount) * $moduleWidth;
            if ($index % 2 === 0) {
                $rectangles[] = '<rect x="' . $x . '" y="0" width="' . $segmentWidth . '" height="' . $height . '"/>';
            }
            $x += $segmentWidth;
        }
    }

    return '<svg class="barcode-svg" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Barcode ' .
        htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '" viewBox="0 0 ' . $width . ' ' . $height .
        '" preserveAspectRatio="none"><g fill="#000">' . implode('', $rectangles) . '</g></svg>';
}
