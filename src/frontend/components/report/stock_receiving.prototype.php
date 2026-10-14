<?php
// Throwaway #176: three bulk-receiving layouts on the existing route; all mutations stay in memory.
if (getenv('RECEIVING_PROTOTYPE') !== '1') { http_response_code(404); exit; }
$preview = $preview ?? false;
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Bulk receiving · Prototype #176</title>
<link rel="stylesheet" href="/assets/css/style.css"><link rel="stylesheet" href="/components/report/stock_receiving.prototype.css"></head><body>
<div class="app-shell">
<?php if (!$preview): include __DIR__ . '/../sidebar.php'; else: ?>
<aside class="prototype-rail"><h2>RetailMind</h2><p>Inventory Manager</p><nav aria-label="Preview workspace"><a href="#stock">Stock monitoring</a><a href="#recommendations">Replenishment Recommendations</a><a href="#entry" aria-current="page">Stock Receiving</a><a href="#history">Receiving history</a></nav><small>Illustrative workspace preview</small></aside>
<?php endif; ?>
<main class="prototype-main"><header><h1>Stock Receiving</h1><span>Inventory Manager workspace</span></header>
<p class="prototype-notice">Throwaway prototype #176 · Sample products and recommendations · Saves affect this page only.</p>
<div id="layout"></div><section id="feedback" role="status" aria-live="polite"></section>
<details class="state"><summary>Current prototype state</summary><div id="state"></div></details>
<nav class="prototype-switcher" aria-label="Prototype variants"><button id="previous" aria-label="Previous variant">←</button><span id="variant-label"></span><button id="next" aria-label="Next variant">→</button><button id="reset">Reset demo</button></nav>
</main></div><script src="/components/report/stock_receiving.prototype.js"></script></body></html>
