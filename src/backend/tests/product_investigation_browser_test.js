'use strict';
const { spawn, spawnSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

function skip(reason) {
    console.log('Product investigation browser workflow: skipped (' + reason + ')');
    process.exit(0);
}

if (process.env.RUN_DB_TESTS !== '1') {
    skip('set RUN_DB_TESTS=1');
}

function resolvePlaywright() {
    const localCandidates = [];
    if (process.env.PLAYWRIGHT_MODULE) localCandidates.push(process.env.PLAYWRIGHT_MODULE);
    localCandidates.push('playwright', 'playwright-core');
    for (const candidate of localCandidates) {
        try { return require(candidate); } catch (e) {}
    }
    skip('Playwright not found');
}

const { chromium } = resolvePlaywright();
const repoRoot = path.resolve(__dirname, '..', '..', '..');
const phpBinary = process.env.PHP_BINARY || 'php';
const fixtureScript = path.join(__dirname, 'support', 'scan_workflow_fixture.php'); // we can reuse or create our own

(async function () {
    // Generate sqlite DB using fixture
    const dbPath = path.join(require('node:os').tmpdir(), 'rm-investigation-' + Date.now() + '.sqlite');
    // For now we just create a simple db fixture
    const fixtureCode = `<?php
        require __DIR__ . '/../../../backend/config/db.php';
        require __DIR__ . '/../../../backend/app/Database/MigrationRunner.php';
        $pdo = new PDO('sqlite:${dbPath}');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new \\App\\Database\\MigrationRunner($pdo, __DIR__ . '/../../../backend/database/migrations'))->runPending();
        $pdo->exec("INSERT INTO users (username, password_hash, role, full_name, status) VALUES ('admin', 'x', 'inventory_manager', 'Admin', 'active')");
        $pdo->exec("INSERT INTO categories (category_name) VALUES ('Cat')");
        $pdo->exec("INSERT INTO products (sku, product_name, category_id, unit_price, cost_price) VALUES ('P1', 'Test Product 1', 1, 10, 5)");
        $pdo->exec("INSERT INTO products (sku, product_name, category_id, unit_price, cost_price) VALUES ('P2', 'Test Product 2', 1, 10, 5)");
        // Add forecasts
        $pdo->exec("INSERT INTO ml_jobs (job_id, trigger_type, status, queued_at) VALUES (1, 'forecast_generation', 'succeeded', '2026-10-09')");
        $pdo->exec("INSERT INTO demand_forecasts (job_id, product_id, forecast_origin, target_date, horizon_day, predicted_units, model_version, readiness_level) VALUES (1, 1, '2026-10-09', '2026-10-10', 1, 5, 'v1', 'High')");
    `;
    const fixtureFile = dbPath + '.php';
    fs.writeFileSync(fixtureFile, fixtureCode);
    spawnSync(phpBinary, [fixtureFile]);
    fs.unlinkSync(fixtureFile);

    const routerCode = `<?php
        $_ENV['DB_NAME'] = '${dbPath}';
        session_start();
        $_SESSION['user_id'] = 1;
        $_SESSION['role'] = 'admin';
        $_SESSION['session_version'] = 1;
        require __DIR__ . '/../../../frontend/index.php';
    `;
    const routerFile = dbPath + '_router.php';
    fs.writeFileSync(routerFile, routerCode);

    const port = 9989;
    const server = spawn(phpBinary, ['-S', `127.0.0.1:${port}`, routerFile], { cwd: repoRoot });
    await new Promise(r => setTimeout(r, 1000));

    let browser, context;
    try {
        browser = await chromium.launch();
        context = await browser.newContext();
        const page = await context.newPage();
        
        // Log in
        await page.goto(`http://127.0.0.1:${port}/?login=1`);
        
        // The router uses session user_id = 1, so we are logged in as admin
        await page.goto(`http://127.0.0.1:${port}/components/report/predictions.php`);
        
        // Assert search form
        await page.waitForSelector('form.search-form input[name="product_id"]');
        await page.fill('input[name="product_id"]', '1');
        await page.click('button:has-text("Investigate")');
        
        // Verify product investigation page
        await page.waitForSelector('h1:has-text("Product Investigation: Test Product 1")');
        
        // Check aggregates
        const aggregates = await page.locator('table.data-table').first().innerText();
        if (!aggregates.includes('5')) throw new Error('Missing 7-day forecast');
        if (!aggregates.includes('High')) throw new Error('Missing readiness');
        
        // Check daily
        const daily = await page.locator('table.data-table').nth(2).innerText();
        if (!daily.includes('2026-10-10')) throw new Error('Missing daily target date');
        if (!daily.includes('5')) throw new Error('Missing daily predicted units');
        
        // Check empty states
        await page.goto(`http://127.0.0.1:${port}/components/inventory_management/product_investigation.php?product_id=999`);
        await page.waitForSelector('h2:has-text("Missing product")');

    } catch (e) {
        console.error(e);
        const html = await page.content();
        console.error("HTML:", html.substring(0, 1000));
        process.exitCode = 1;
    } finally {
        if (browser) await browser.close();
        server.kill();
        try { fs.unlinkSync(dbPath); } catch (e) {}
        try { fs.unlinkSync(routerFile); } catch (e) {}
    }
})();
