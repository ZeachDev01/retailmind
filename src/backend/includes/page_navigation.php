<?php
// Keep normal PHP entry points and their access checks; only change their HTML response.
function retailmind_navigation_response(string $html): string
{
    if (!str_contains($html, '<!--rm-shell-start-->') || !str_contains($html, '<!--rm-shell-end-->')) {
        return $html;
    }
    [$before, $rest] = explode('<!--rm-shell-start-->', $html, 2);
    [$shell, $after] = explode('<!--rm-shell-end-->', $rest, 2);
    $scopeScripts = static function (string $part): string {
        $part = preg_replace_callback('~<script\b([^>]*)>(.*?)</script\s*>~is', static function (array $script): string {
            if (preg_match('~\btype\s*=\s*["\'](?!text/javascript|application/javascript|module)[^"\']+~i', $script[1])) {
                return $script[0];
            }
            $source = preg_match('~\bsrc\s*=~i', $script[1]) || preg_match('~\btype\s*=\s*["\']module~i', $script[1])
                ? $script[2] : "{\n" . $script[2] . "\n}";
            return '<script data-rm-page' . $script[1] . '>' . $source . '</script>';
        }, $part) ?? $part;
        return preg_replace('~<script\b[^>]*>.*?</script\s*>(*SKIP)(*F)|<(style|link)\b~is', '<$1 data-rm-page-asset', $part) ?? $part;
    };
    $before = $scopeScripts($before);
    $after = $scopeScripts($after);
    header('Vary: X-RetailMind-Navigation', false);
    if (($_SERVER['HTTP_X_RETAILMIND_NAVIGATION'] ?? '') !== '1') {
        return $before . '<!--rm-shell-start-->' . $shell . '<!--rm-shell-end-->' . $after;
    }
    // The shell is excluded, including its scripts, so it cannot be duplicated on navigation.
    header('Content-Type: application/vnd.retailmind.page+json; charset=utf-8');
    header('Cache-Control: no-store');
    return json_encode(['html' => $before . $after, 'role' => current_role(), 'flash' => $GLOBALS['rm_navigation_flash'] ?? [],
        'shell' => $GLOBALS['rm_navigation_shell'] ?? [], 'cart' => $GLOBALS['rm_navigation_cart'] ?? null],
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
}

ob_start('retailmind_navigation_response');
