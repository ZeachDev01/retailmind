<?php

declare(strict_types=1);

// mobile_qr_modal_contract.php
// Contract and integration tests for Ticket #442:
// - Self-contained offline QR code generation seam (pure PHP SVG generator & vendor asset)
// - Dedicated QR code modal in Store Configuration (store_settings.php)
// - One-click clipboard copy with visual confirmation feedback triggers
// - Responsive layout adaptation across mobile, tablet, and desktop viewports
// - API endpoint support for dynamic QR generation

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
        echo "FAIL: {$message}\n";
    } else {
        echo "PASS: {$message}\n";
    }
};

$root = dirname(__DIR__, 3);

echo "=== 1. Self-Contained Offline QR Code Generator Seam ===\n";

$serviceFile = $root . '/src/backend/app/Services/QrCodeService.php';
$serviceClass = 'App\\Services\\QrCodeService';

$assert(file_exists($serviceFile), "QrCodeService.php file exists at {$serviceFile}");
if (file_exists($serviceFile)) {
    require_once $serviceFile;
}
$assert(class_exists($serviceClass), "Class {$serviceClass} exists");

if (class_exists($serviceClass)) {
    $qr = new $serviceClass();
    $testUrl = 'https://amber-crystal-beacon.trycloudflare.com';

    // Test matrix generation
    $matrix = $qr->generateMatrix($testUrl, 'M');
    $assert(is_array($matrix), "generateMatrix returns an array");
    $size = count($matrix);
    $assert($size >= 21 && ($size - 21) % 4 === 0, "QR matrix has valid QR version dimensions (size: {$size})");
    $assert(count($matrix[0]) === $size, "QR matrix is square ({$size}x{$size})");

    // Test finder patterns at corners (7x7 pattern with top-left, top-right, bottom-left)
    // Finder pattern center (row 3, col 3) must be 1 (dark module)
    $assert($matrix[3][3] === 1, "Top-left finder pattern center is dark (1)");
    $assert($matrix[3][$size - 4] === 1, "Top-right finder pattern center is dark (1)");
    $assert($matrix[$size - 4][3] === 1, "Bottom-left finder pattern center is dark (1)");

    // Finder pattern separator boundary (e.g. row 0 col 7 must be 0/white separator)
    $assert($matrix[0][7] === 0, "Top-left separator column is light (0)");
    $assert($matrix[7][0] === 0, "Top-left separator row is light (0)");

    // Test SVG generation
    $svg = $qr->generateSvg($testUrl, 256, 4);
    $assert(is_string($svg), "generateSvg returns string");
    $assert(str_starts_with(trim($svg), '<svg') || str_contains($svg, '<svg'), "SVG starts with <svg tag");
    $assert(str_contains($svg, '</svg>'), "SVG contains closing </svg> tag");
    $assert(str_contains($svg, 'viewBox='), "SVG specifies viewBox for responsive scaling");
    $assert(str_contains($svg, 'path') || str_contains($svg, 'rect'), "SVG contains QR module drawing elements (path or rect)");

    // Test offline self-contained operation: no http/https URLs or external API calls inside generated SVG
    $assert(!str_contains($svg, 'api.qrserver.com'), "Does not use api.qrserver.com");
    $assert(!str_contains($svg, 'chart.googleapis.com'), "Does not use googleapis.com");
    $assert(!str_contains($svg, 'quickchart.io'), "Does not use quickchart.io");

    // Test data URI generation
    $dataUri = $qr->generateDataUri($testUrl, 256);
    $assert(str_starts_with($dataUri, 'data:image/svg+xml;'), "generateDataUri returns data:image/svg+xml URI");

    // Test local IP URL and edge cases
    $localUrl = 'http://192.168.1.150:8000';
    $localSvg = $qr->generateSvg($localUrl);
    $assert(str_contains($localSvg, '<svg') && str_contains($localSvg, '</svg>'), "Generates valid SVG for local network URL");

    $shortSvg = $qr->generateSvg('RM');
    $assert(str_contains($shortSvg, '<svg'), "Generates valid SVG for short text");
}

echo "\n=== 2. Vendor Local Script Asset Presence ===\n";

$vendorJs = $root . '/src/frontend/assets/vendor/qrcode/qrcode.min.js';
$assert(file_exists($vendorJs), "Vendor asset qrcode.min.js exists locally without CDN dependency");
if (file_exists($vendorJs)) {
    $vendorContent = file_get_contents($vendorJs);
    $assert(strlen($vendorContent) > 500, "Vendor qrcode.min.js contains valid script content");
    $assert(!str_contains($vendorContent, 'api.qrserver.com') && !str_contains($vendorContent, 'googleapis.com') && !str_contains($vendorContent, 'cdn.'), "Vendor script contains no external third-party API dependencies");
}

echo "\n=== 3. Remote Access API Endpoint Contract ===\n";

$apiFile = $root . '/src/frontend/components/administrator/remote_access_api.php';
$assert(file_exists($apiFile), "remote_access_api.php exists");
if (file_exists($apiFile)) {
    $apiContent = file_get_contents($apiFile);
    $assert(str_contains($apiContent, 'qr_svg') || str_contains($apiContent, 'qrcode'), "remote_access_api.php supports QR SVG or qrcode action");
}

echo "\n=== 4. Store Configuration UI QR Modal & Clipboard Copy Contract ===\n";

$storeSettingsFile = $root . '/src/frontend/components/administrator/store_settings.php';
$assert(file_exists($storeSettingsFile), "store_settings.php exists");

if (file_exists($storeSettingsFile)) {
    $content = file_get_contents($storeSettingsFile);

    // Modal presence and accessible dialog attributes
    $assert(str_contains($content, 'id="qr-code-modal"') || str_contains($content, 'id="remote-qr-modal"'), "Store settings contains dedicated QR modal element");
    $assert(str_contains($content, 'role="dialog"') || str_contains($content, '<dialog'), "QR modal has role='dialog' or <dialog> tag");
    $assert(str_contains($content, 'aria-modal="true"'), "QR modal has aria-modal='true' attribute");
    $assert(str_contains($content, 'aria-labelledby='), "QR modal has accessible label via aria-labelledby");

    // Modal trigger button in Remote Access card
    $assert(str_contains($content, 'id="show-qr-code-btn"') || str_contains($content, 'id="open-qr-modal-btn"'), "Store settings contains button to open QR modal");

    // QR code display target container
    $assert(str_contains($content, 'id="qr-code-display"') || str_contains($content, 'id="remote-qr-display"'), "QR modal contains QR display container element");

    // Close button for modal
    $assert(str_contains($content, 'id="close-qr-modal-btn"') || str_contains($content, 'class="modal-close-btn"'), "QR modal contains close button");

    // Copy remote URL buttons (both card and modal)
    $assert(str_contains($content, 'id="copy-remote-url-btn"'), "Store settings has card-level copy button");
    $assert(str_contains($content, 'id="modal-copy-url-btn"'), "QR modal has modal-level copy button");

    // Copy visual confirmation feedback triggers
    $assert(str_contains($content, 'Copied!'), "Store settings script contains visual confirmation text 'Copied!'");
    $assert(str_contains($content, 'navigator.clipboard'), "Script uses modern clipboard API");
    $assert(str_contains($content, 'execCommand'), "Script includes fallback copy mechanism");

    // Modal keyboard accessibility (Escape key to dismiss with scoped listener lifecycle)
    $assert(str_contains($content, 'Escape') || str_contains($content, 'keyCode === 27'), "Modal closes on Escape key press");
    $assert(str_contains($content, "removeEventListener('keydown', handleModalEscape)"), "Modal Escape key listener detaches on close");
    $assert(!preg_match('/document\.addEventListener\(\s*[\'"]keydown[\'"]\s*,\s*function/', $content), "Store settings does not attach unbounded global Escape key listener");

    // DOM node stacking guardrail
    $assert(str_contains($content, "qrDisplay.innerHTML = ''"), "renderQrCode clears qrDisplay before rendering to prevent DOM node stacking");

    // Link integrity
    $assert(!str_contains($content, 'DEPLOYMENT_REMOTE_TUNNEL.md'), "Store settings does not contain dead relative link to markdown file");
    $assert(str_contains($content, "app_url('components/auth/help.php#tunnel-deployment')"), "Store settings uses compliant in-app app_url link for setup guide");
}

echo "\n=== 5. Responsive Styling & Viewport Adaptation Contract ===\n";

$cssFile = $root . '/src/frontend/assets/css/store-settings.css';
$assert(file_exists($cssFile), "store-settings.css exists");

if (file_exists($cssFile)) {
    $css = file_get_contents($cssFile);

    // Modal overlay and dialog structure
    $assert(str_contains($css, '.qr-modal-backdrop') || str_contains($css, '.qr-modal-overlay') || str_contains($css, '#qr-code-modal'), "CSS contains QR modal backdrop/overlay rules");
    $assert(str_contains($css, '.qr-modal-dialog') || str_contains($css, '.qr-modal-content'), "CSS contains modal dialog container rules");

    // QR code presentation styles
    $assert(str_contains($css, '.qr-code-display') || str_contains($css, '#qr-code-display'), "CSS contains QR display container styling");

    // Responsive adaptation rules (media queries for mobile / tablet / desktop)
    $assert(str_contains($css, '@media'), "CSS defines responsive media queries");
    $assert(preg_match('/@media[^{]*(max-width|min-width)[^{]*\{[^}]*(qr-modal|qr-code)/s', $css) === 1, "CSS includes responsive media queries specifically adapting QR modal dialog");
}

echo "\n==========================================\n";
if (!empty($failures)) {
    echo "FAILED: " . count($failures) . " contract tests failed.\n";
    exit(1);
} else {
    echo "SUCCESS: All mobile QR modal contract tests passed!\n";
    exit(0);
}
