<?php
declare(strict_types=1);

/**
 * Fast deterministic view markup audit script.
 * Checks PHP views for basic accessibility, duplicate IDs, button types, and label associations.
 *
 * Usage:
 *   php src/backend/scripts/audit_views.php
 *   php src/backend/scripts/audit_views.php --strict
 */

$root = dirname(__DIR__, 3);
$targetDir = $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'frontend' . DIRECTORY_SEPARATOR . 'components';
$isStrict = in_array('--strict', $argv, true);

if (!is_dir($targetDir)) {
    fwrite(STDERR, "Target directory not found: {$targetDir}\n");
    exit(1);
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($targetDir, FilesystemIterator::SKIP_DOTS)
);

$scanned = 0;
$issues = [];

foreach ($iterator as $fileInfo) {
    if (!$fileInfo->isFile() || $fileInfo->getExtension() !== 'php') {
        continue;
    }

    $filePath = $fileInfo->getPathname();
    $relPath = str_replace($root . DIRECTORY_SEPARATOR, '', $filePath);
    $relPath = str_replace('\\', '/', $relPath);
    $scanned++;

    $content = file_get_contents($filePath);
    if ($content === false) {
        continue;
    }

    $lines = explode("\n", $content);

    // 1. Check duplicate static IDs in same file
    $idMatches = [];
    preg_match_all('/\bid=["\']([a-zA-Z0-9_-]+)["\']/', $content, $idMatches, PREG_OFFSET_CAPTURE);
    if (!empty($idMatches[1])) {
        $idCounts = [];
        foreach ($idMatches[1] as $match) {
            $id = $match[0];
            $offset = $match[1];
            // Skip dynamic php interpolation
            if (str_contains($id, '<?')) {
                continue;
            }
            $lineNum = substr_count(substr($content, 0, $offset), "\n") + 1;
            $idCounts[$id][] = $lineNum;
        }

        foreach ($idCounts as $id => $lineNums) {
            if (count($lineNums) > 1) {
                // If ID is inside modal or script templates, note as warning
                $issues[] = [
                    'severity' => 'warning',
                    'file' => $relPath,
                    'line' => $lineNums[0],
                    'message' => "Duplicate ID '{$id}' found on lines: " . implode(', ', $lineNums),
                ];
            }
        }
    }

    // 2. Check for button tags without type attribute
    foreach ($lines as $idx => $line) {
        $lineNum = $idx + 1;
        if (preg_match('/<button\b([^>]*?)>/i', $line, $btnMatch)) {
            $attrs = $btnMatch[1];
            if (!preg_match('/\btype\s*=\s*["\'](button|submit|reset)["\']/i', $attrs)) {
                $issues[] = [
                    'severity' => 'warning',
                    'file' => $relPath,
                    'line' => $lineNum,
                    'message' => "<button> element missing explicit type attribute (defaults to type=\"submit\").",
                ];
            }
        }
    }

    // 3. Check for label without matching ID
    preg_match_all('/<label\b[^>]*\bfor=["\']([a-zA-Z0-9_-]+)["\']/i', $content, $labelMatches, PREG_OFFSET_CAPTURE);
    if (!empty($labelMatches[1])) {
        foreach ($labelMatches[1] as $lm) {
            $forId = $lm[0];
            $offset = $lm[1];
            if (str_contains($forId, '<?')) {
                continue;
            }
            // Check if ID exists anywhere in the file
            $pattern = '/\bid=["\']' . preg_quote($forId, '/') . '["\']/';
            if (!preg_match($pattern, $content)) {
                $lineNum = substr_count(substr($content, 0, $offset), "\n") + 1;
                $issues[] = [
                    'severity' => 'warning',
                    'file' => $relPath,
                    'line' => $lineNum,
                    'message' => "<label for=\"{$forId}\"> has no matching element with id=\"{$forId}\" in file.",
                ];
            }
        }
    }
}

// Print results
echo "=== RetailMind View Markup Audit ===\n";
echo "Scanned: {$scanned} PHP views in src/frontend/components\n";

$errorCount = 0;
$warnCount = 0;

foreach ($issues as $issue) {
    if ($issue['severity'] === 'error') {
        $errorCount++;
        echo " [ERROR] {$issue['file']}:{$issue['line']} - {$issue['message']}\n";
    } else {
        $warnCount++;
        // Limit warning output volume if large
        if ($warnCount <= 30) {
            echo " [WARN]  {$issue['file']}:{$issue['line']} - {$issue['message']}\n";
        }
    }
}

if ($warnCount > 30) {
    $remaining = $warnCount - 30;
    echo " ... and {$remaining} more warnings.\n";
}

echo "Summary: {$errorCount} errors, {$warnCount} warnings.\n";

if ($isStrict && ($errorCount > 0 || $warnCount > 0)) {
    exit(1);
}

if ($errorCount > 0) {
    exit(1);
}

exit(0);
