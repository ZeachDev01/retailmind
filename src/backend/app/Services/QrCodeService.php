<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Self-contained offline QR code generator producing scannable SVG and data URIs.
 * Zero external dependencies or network requests.
 * Standard ISO/IEC 18004 byte-mode QR matrix algorithm with Reed-Solomon error correction.
 */
class QrCodeService
{
    private const EC_LEVELS = [
        'L' => 1,
        'M' => 0,
        'Q' => 3,
        'H' => 2,
    ];

    // Total codewords and EC codeword definitions for Versions 1-10
    // [version => [total_codewords, [EC_L => [num_blocks_1, data_per_block_1, num_blocks_2, data_per_block_2, ec_per_block], ...]]]
    private const VERSION_TABLE = [
        1 => [26, [
            'L' => [1, 19, 0, 0, 7],
            'M' => [1, 16, 0, 0, 10],
            'Q' => [1, 13, 0, 0, 13],
            'H' => [1, 9, 0, 0, 17],
        ]],
        2 => [44, [
            'L' => [1, 34, 0, 0, 10],
            'M' => [1, 28, 0, 0, 16],
            'Q' => [1, 22, 0, 0, 22],
            'H' => [1, 16, 0, 0, 28],
        ]],
        3 => [70, [
            'L' => [1, 55, 0, 0, 15],
            'M' => [1, 44, 0, 0, 26],
            'Q' => [2, 17, 0, 0, 18],
            'H' => [2, 13, 0, 0, 22],
        ]],
        4 => [100, [
            'L' => [1, 80, 0, 0, 20],
            'M' => [2, 32, 0, 0, 18],
            'Q' => [2, 24, 0, 0, 26],
            'H' => [4, 9, 0, 0, 16],
        ]],
        5 => [134, [
            'L' => [1, 108, 0, 0, 26],
            'M' => [2, 43, 0, 0, 24],
            'Q' => [2, 15, 2, 16, 18],
            'H' => [2, 11, 2, 12, 22],
        ]],
        6 => [172, [
            'L' => [2, 68, 0, 0, 18],
            'M' => [4, 27, 0, 0, 16],
            'Q' => [4, 19, 0, 0, 24],
            'H' => [4, 15, 0, 0, 28],
        ]],
        7 => [196, [
            'L' => [2, 78, 0, 0, 20],
            'M' => [4, 31, 0, 0, 18],
            'Q' => [2, 14, 4, 15, 18],
            'H' => [4, 13, 1, 14, 26],
        ]],
        8 => [242, [
            'L' => [2, 97, 0, 0, 24],
            'M' => [2, 38, 2, 39, 22],
            'Q' => [4, 18, 2, 19, 22],
            'H' => [4, 14, 2, 15, 26],
        ]],
        9 => [292, [
            'L' => [2, 116, 0, 0, 30],
            'M' => [3, 36, 2, 37, 22],
            'Q' => [4, 16, 4, 17, 20],
            'H' => [4, 12, 4, 13, 24],
        ]],
        10 => [346, [
            'L' => [2, 68, 2, 69, 18],
            'M' => [4, 43, 1, 44, 26],
            'Q' => [6, 19, 2, 20, 24],
            'H' => [6, 15, 2, 16, 28],
        ]],
    ];

    private const ALIGNMENT_PATTERN_LOCATIONS = [
        1 => [],
        2 => [6, 18],
        3 => [6, 22],
        4 => [6, 26],
        5 => [6, 30],
        6 => [6, 34],
        7 => [6, 22, 38],
        8 => [6, 24, 42],
        9 => [6, 26, 46],
        10 => [6, 28, 50],
    ];

    private array $exp = [];
    private array $log = [];

    public function __construct()
    {
        $this->initGaloisField();
    }

    private function initGaloisField(): void
    {
        $this->exp = array_fill(0, 512, 0);
        $this->log = array_fill(0, 256, 0);
        $val = 1;
        for ($i = 0; $i < 255; $i++) {
            $this->exp[$i] = $val;
            $this->log[$val] = $i;
            $val <<= 1;
            if ($val & 256) {
                $val ^= 0x11D; // primitive poly x^8 + x^4 + x^3 + x^2 + 1
            }
        }
        for ($i = 255; $i < 512; $i++) {
            $this->exp[$i] = $this->exp[$i - 255];
        }
    }

    private function gfMul(int $x, int $y): int
    {
        if ($x === 0 || $y === 0) {
            return 0;
        }
        return $this->exp[$this->log[$x] + $this->log[$y]];
    }

    private function rsGeneratorPoly(int $numEcBytes): array
    {
        $poly = [1];
        for ($i = 0; $i < $numEcBytes; $i++) {
            $next = [];
            $factor = $this->exp[$i];
            for ($j = 0; $j < count($poly); $j++) {
                $next[$j] = $poly[$j] ? $this->gfMul($poly[$j], $factor) : 0;
            }
            $next[] = 0;
            $shifted = array_merge([0], $poly);
            $poly = [];
            for ($j = 0; $j < count($next); $j++) {
                $poly[$j] = ($shifted[$j] ?? 0) ^ $next[$j];
            }
        }
        return $poly;
    }

    private function rsEncode(array $data, int $numEcBytes): array
    {
        $gen = $this->rsGeneratorPoly($numEcBytes);
        $msg = array_pad($data, count($data) + $numEcBytes, 0);
        for ($i = 0; $i < count($data); $i++) {
            $lead = $msg[$i];
            if ($lead !== 0) {
                for ($j = 0; $j < count($gen); $j++) {
                    $msg[$i + $j] ^= $this->gfMul($gen[$j], $lead);
                }
            }
        }
        return array_slice($msg, count($data));
    }

    /**
     * Generate 2D binary matrix (0 for white, 1 for black) for the given input text.
     */
    public function generateMatrix(string $text, string $ecLevel = 'M'): array
    {
        $ecLevel = strtoupper($ecLevel);
        if (!isset(self::EC_LEVELS[$ecLevel])) {
            $ecLevel = 'M';
        }

        $bytes = array_values(unpack('C*', $text) ?: []);
        $dataLen = count($bytes);

        // Find smallest fitting version (1..10)
        $chosenVersion = null;
        $versionConfig = null;
        foreach (self::VERSION_TABLE as $ver => [$totalCodewords, $ecConfigs]) {
            $cfg = $ecConfigs[$ecLevel];
            $totalDataCapacity = ($cfg[0] * $cfg[1]) + ($cfg[2] * $cfg[3]);
            // Byte mode overhead: 4 bits mode + 8 bits (for ver 1-9) or 16 bits (ver 10) char count
            $charBits = $ver >= 10 ? 16 : 8;
            $maxDataBytes = (int)floor(($totalDataCapacity * 8 - 4 - $charBits) / 8);
            if ($dataLen <= $maxDataBytes) {
                $chosenVersion = $ver;
                $versionConfig = $cfg;
                break;
            }
        }

        if ($chosenVersion === null) {
            // Fallback to version 10
            $chosenVersion = 10;
            $versionConfig = self::VERSION_TABLE[10][1][$ecLevel];
        }

        // 1. Bitstream assembly
        $bits = '';
        // Mode indicator for Byte: 0100
        $bits .= '0100';
        // Character count indicator
        $charBits = $chosenVersion >= 10 ? 16 : 8;
        $bits .= str_pad(decbin($dataLen), $charBits, '0', STR_PAD_LEFT);
        // Data bits
        foreach ($bytes as $b) {
            $bits .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
        }

        $totalDataCapacity = ($versionConfig[0] * $versionConfig[1]) + ($versionConfig[2] * $versionConfig[3]);
        $targetBitLen = $totalDataCapacity * 8;

        // Terminator (up to 4 zero bits)
        $bits .= substr('0000', 0, max(0, min(4, $targetBitLen - strlen($bits))));

        // Padding to byte boundary
        if (strlen($bits) % 8 !== 0) {
            $bits .= str_repeat('0', 8 - (strlen($bits) % 8));
        }

        // Pad bytes (0xEC, 0x11 alternating)
        $padToggle = false;
        while (strlen($bits) < $targetBitLen) {
            $bits .= $padToggle ? '00010001' : '11101100';
            $padToggle = !$padToggle;
        }

        // Convert bits to data codewords
        $dataCodewords = [];
        for ($i = 0; $i < strlen($bits); $i += 8) {
            $dataCodewords[] = bindec(substr($bits, $i, 8));
        }

        // 2. Block division and Reed-Solomon EC calculation
        $blocksData = [];
        $blocksEc = [];
        $offset = 0;
        $numBlocks1 = $versionConfig[0];
        $dataLen1 = $versionConfig[1];
        $numBlocks2 = $versionConfig[2];
        $dataLen2 = $versionConfig[3];
        $ecBytesPerBlock = $versionConfig[4];

        for ($b = 0; $b < $numBlocks1; $b++) {
            $block = array_slice($dataCodewords, $offset, $dataLen1);
            $blocksData[] = $block;
            $blocksEc[] = $this->rsEncode($block, $ecBytesPerBlock);
            $offset += $dataLen1;
        }
        for ($b = 0; $b < $numBlocks2; $b++) {
            $block = array_slice($dataCodewords, $offset, $dataLen2);
            $blocksData[] = $block;
            $blocksEc[] = $this->rsEncode($block, $ecBytesPerBlock);
            $offset += $dataLen2;
        }

        // 3. Interleaving data and error correction codewords
        $finalCodewords = [];
        $maxDataLen = max($dataLen1, $dataLen2);
        for ($i = 0; $i < $maxDataLen; $i++) {
            foreach ($blocksData as $block) {
                if (isset($block[$i])) {
                    $finalCodewords[] = $block[$i];
                }
            }
        }
        for ($i = 0; $i < $ecBytesPerBlock; $i++) {
            foreach ($blocksEc as $ecBlock) {
                if (isset($ecBlock[$i])) {
                    $finalCodewords[] = $ecBlock[$i];
                }
            }
        }

        // Convert final codewords to raw bitstream
        $finalBits = '';
        foreach ($finalCodewords as $cw) {
            $finalBits .= str_pad(decbin($cw), 8, '0', STR_PAD_LEFT);
        }

        // Add remainder bits if needed for version
        $remainderBits = [1 => 0, 2 => 7, 3 => 7, 4 => 7, 5 => 7, 6 => 7, 7 => 0, 8 => 0, 9 => 0, 10 => 0];
        if (!empty($remainderBits[$chosenVersion])) {
            $finalBits .= str_repeat('0', $remainderBits[$chosenVersion]);
        }

        // 4. Matrix creation and function pattern placement
        $matrixDim = 17 + ($chosenVersion * 4);
        $matrix = array_fill(0, $matrixDim, array_fill(0, $matrixDim, null));
        $reserved = array_fill(0, $matrixDim, array_fill(0, $matrixDim, false));

        // Place Finder Patterns
        $this->placeFinderPattern($matrix, $reserved, 0, 0);
        $this->placeFinderPattern($matrix, $reserved, 0, $matrixDim - 7);
        $this->placeFinderPattern($matrix, $reserved, $matrixDim - 7, 0);

        // Place Timing Patterns
        for ($i = 8; $i < $matrixDim - 8; $i++) {
            $val = ($i % 2 === 0) ? 1 : 0;
            if (!$reserved[6][$i]) {
                $matrix[6][$i] = $val;
                $reserved[6][$i] = true;
            }
            if (!$reserved[$i][6]) {
                $matrix[$i][6] = $val;
                $reserved[$i][6] = true;
            }
        }

        // Place Alignment Patterns
        $alignPos = self::ALIGNMENT_PATTERN_LOCATIONS[$chosenVersion] ?? [];
        foreach ($alignPos as $r) {
            foreach ($alignPos as $c) {
                // Skip if overlapping with finder patterns
                if (($r <= 8 && $c <= 8) || ($r <= 8 && $c >= $matrixDim - 8) || ($r >= $matrixDim - 8 && $c <= 8)) {
                    continue;
                }
                $this->placeAlignmentPattern($matrix, $reserved, $r, $c);
            }
        }

        // Dark module
        $matrix[4 * $chosenVersion + 9][8] = 1;
        $reserved[4 * $chosenVersion + 9][8] = true;

        // Reserve format information areas
        for ($i = 0; $i < 9; $i++) {
            $reserved[8][$i] = true;
            $reserved[$i][8] = true;
        }
        for ($i = $matrixDim - 8; $i < $matrixDim; $i++) {
            $reserved[8][$i] = true;
            $reserved[$i][8] = true;
        }

        // 5. Place data bits in matrix (zigzag)
        $bitIdx = 0;
        $totalBits = strlen($finalBits);
        $dirUp = true;
        for ($col = $matrixDim - 1; $col > 0; $col -= 2) {
            if ($col === 6) {
                $col--; // Skip vertical timing column
            }
            $rows = $dirUp ? range($matrixDim - 1, 0, -1) : range(0, $matrixDim - 1);
            foreach ($rows as $r) {
                foreach ([$col, $col - 1] as $c) {
                    if (!$reserved[$r][$c]) {
                        $bit = ($bitIdx < $totalBits) ? (int)$finalBits[$bitIdx] : 0;
                        $matrix[$r][$c] = $bit;
                        $bitIdx++;
                    }
                }
            }
            $dirUp = !$dirUp;
        }

        // 6. Select Best Mask (0..7) based on penalty score
        $bestMask = 0;
        $lowestPenalty = PHP_INT_MAX;
        $bestMatrix = null;

        for ($mask = 0; $mask < 8; $mask++) {
            $masked = $matrix;
            for ($r = 0; $r < $matrixDim; $r++) {
                for ($c = 0; $c < $matrixDim; $c++) {
                    if (!$reserved[$r][$c]) {
                        if ($this->maskCondition($mask, $r, $c)) {
                            $masked[$r][$c] = $matrix[$r][$c] ^ 1;
                        }
                    }
                }
            }
            $this->applyFormatInfo($masked, $ecLevel, $mask);
            $penalty = $this->calculatePenalty($masked, $matrixDim);
            if ($penalty < $lowestPenalty) {
                $lowestPenalty = $penalty;
                $bestMask = $mask;
                $bestMatrix = $masked;
            }
        }

        return $bestMatrix ?? $matrix;
    }

    private function placeFinderPattern(array &$matrix, array &$reserved, int $row, int $col): void
    {
        for ($r = -1; $r <= 7; $r++) {
            for ($c = -1; $c <= 7; $c++) {
                $currR = $row + $r;
                $currC = $col + $c;
                if ($currR < 0 || $currR >= count($matrix) || $currC < 0 || $currC >= count($matrix)) {
                    continue;
                }
                $reserved[$currR][$currC] = true;
                if ($r >= 0 && $r <= 6 && $c >= 0 && $c <= 6) {
                    if ($r === 0 || $r === 6 || $c === 0 || $c === 6 || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4)) {
                        $matrix[$currR][$currC] = 1;
                    } else {
                        $matrix[$currR][$currC] = 0;
                    }
                } else {
                    $matrix[$currR][$currC] = 0; // Separator
                }
            }
        }
    }

    private function placeAlignmentPattern(array &$matrix, array &$reserved, int $centerRow, int $centerCol): void
    {
        for ($r = -2; $r <= 2; $r++) {
            for ($c = -2; $c <= 2; $c++) {
                $currR = $centerRow + $r;
                $currC = $centerCol + $c;
                $reserved[$currR][$currC] = true;
                if (abs($r) === 2 || abs($c) === 2 || ($r === 0 && $c === 0)) {
                    $matrix[$currR][$currC] = 1;
                } else {
                    $matrix[$currR][$currC] = 0;
                }
            }
        }
    }

    private function maskCondition(int $mask, int $r, int $c): bool
    {
        return match ($mask) {
            0 => ($r + $c) % 2 === 0,
            1 => $r % 2 === 0,
            2 => $c % 3 === 0,
            3 => ($r + $c) % 3 === 0,
            4 => ((int)floor($r / 2) + (int)floor($c / 3)) % 2 === 0,
            5 => (($r * $c) % 2 + ($r * $c) % 3) === 0,
            6 => ((($r * $c) % 2 + ($r * $c) % 3) % 2) === 0,
            7 => ((($r + $c) % 2 + ($r * $c) % 3) % 2) === 0,
            default => false,
        };
    }

    private function applyFormatInfo(array &$matrix, string $ecLevel, int $mask): void
    {
        $dim = count($matrix);
        $data = (self::EC_LEVELS[$ecLevel] << 3) | $mask;
        // BCH error correction: divide data << 10 by generator 0x537
        $bch = $data << 10;
        $gen = 0x537;
        for ($i = 14; $i >= 10; $i--) {
            if ($bch & (1 << $i)) {
                $bch ^= ($gen << ($i - 10));
            }
        }
        $formatBits = (($data << 10) | $bch) ^ 0x5412; // XOR with mask 0x5412

        // Top-left format bits placement
        $tlPositions = [
            [8, 0], [8, 1], [8, 2], [8, 3], [8, 4], [8, 5],
            [8, 7], [8, 8], [7, 8],
            [5, 8], [4, 8], [3, 8], [2, 8], [1, 8], [0, 8]
        ];
        // Split positions along edges
        for ($i = 0; $i < 15; $i++) {
            $bit = ($formatBits >> $i) & 1;
            [$r, $c] = $tlPositions[$i];
            $matrix[$r][$c] = $bit;
        }

        // Second format bits copy near timing / other corners
        for ($i = 0; $i < 7; $i++) {
            $bit = ($formatBits >> $i) & 1;
            $matrix[$dim - 1 - $i][8] = $bit;
        }
        for ($i = 7; $i < 15; $i++) {
            $bit = ($formatBits >> $i) & 1;
            $matrix[8][$dim - 15 + $i] = $bit;
        }
    }

    private function calculatePenalty(array $matrix, int $dim): int
    {
        $penalty = 0;
        // Condition 1: 5 or more consecutive identical modules
        for ($r = 0; $r < $dim; $r++) {
            $run = 1;
            for ($c = 1; $c < $dim; $c++) {
                if ($matrix[$r][$c] === $matrix[$r][$c - 1]) {
                    $run++;
                } else {
                    if ($run >= 5) {
                        $penalty += 3 + ($run - 5);
                    }
                    $run = 1;
                }
            }
            if ($run >= 5) {
                $penalty += 3 + ($run - 5);
            }
        }
        // Repeat for columns
        for ($c = 0; $c < $dim; $c++) {
            $run = 1;
            for ($r = 1; $r < $dim; $r++) {
                if ($matrix[$r][$c] === $matrix[$r - 1][$c]) {
                    $run++;
                } else {
                    if ($run >= 5) {
                        $penalty += 3 + ($run - 5);
                    }
                    $run = 1;
                }
            }
            if ($run >= 5) {
                $penalty += 3 + ($run - 5);
            }
        }
        // Condition 2: 2x2 blocks of same color
        for ($r = 0; $r < $dim - 1; $r++) {
            for ($c = 0; $c < $dim - 1; $c++) {
                $val = $matrix[$r][$c];
                if ($matrix[$r + 1][$c] === $val && $matrix[$r][$c + 1] === $val && $matrix[$r + 1][$c + 1] === $val) {
                    $penalty += 3;
                }
            }
        }
        return $penalty;
    }

    /**
     * Render QR code as responsive self-contained SVG.
     */
    public function generateSvg(string $text, int $size = 256, int $margin = 4, string $ecLevel = 'M'): string
    {
        $matrix = $this->generateMatrix($text, $ecLevel);
        $moduleCount = count($matrix);
        $totalSize = $moduleCount + ($margin * 2);

        $pathD = '';
        for ($r = 0; $r < $moduleCount; $r++) {
            for ($c = 0; $c < $moduleCount; $c++) {
                if ($matrix[$r][$c] === 1) {
                    $x = $c + $margin;
                    $y = $r + $margin;
                    $pathD .= "M{$x},{$y}h1v1h-1z ";
                }
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $totalSize . ' ' . $totalSize . '" width="' . $size . '" height="' . $size . '" aria-label="QR Code" shape-rendering="crispEdges">' .
            '<rect width="100%" height="100%" fill="#ffffff"/>' .
            '<path fill="#000000" d="' . trim($pathD) . '"/>' .
            '</svg>';
    }

    /**
     * Render QR code as SVG data URI.
     */
    public function generateDataUri(string $text, int $size = 256, int $margin = 4, string $ecLevel = 'M'): string
    {
        $svg = $this->generateSvg($text, $size, $margin, $ecLevel);
        return 'data:image/svg+xml;utf8,' . rawurlencode($svg);
    }
}
