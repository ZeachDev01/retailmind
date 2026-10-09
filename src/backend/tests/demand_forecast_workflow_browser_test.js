'use strict';
// Browser-to-HTTP workflow for Demand Forecast generation.

const { spawn, spawnSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

const repoRoot = path.resolve(__dirname, '..', '..', '..');
const fixtureScript = path.join(__dirname, 'support', 'scan_workflow_fixture.php');
const phpBinary = process.env.PHP_BINARY || 'php';

function skip(reason) {
    console.log('Demand forecast workflow: skipped (' + reason + ')');
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

(async function () {
    const token = Math.random().toString(36).substring(2, 8);
    const fixtureProcess = spawnSync(phpBinary, [fixtureScript, '--token', token, '--create'], { cwd: repoRoot, env: process.env });
    if (fixtureProcess.status !== 0) {
        console.error('Fixture failed', fixtureProcess.stderr.toString());
        process.exit(1);
    }
    const config = JSON.parse(fixtureProcess.stdout.toString());

    const port = 9989;
    const serverEnv = Object.assign({}, process.env, { APP_URL: `http://127.0.0.1:${port}` });
    const server = spawn(phpBinary, ['-S', `127.0.0.1:${port}`, '-t', path.join(repoRoot, 'src', 'frontend')], { cwd: repoRoot, env: serverEnv });
    server.stdout.pipe(process.stdout);
    server.stderr.pipe(process.stderr);
    await new Promise(r => setTimeout(r, 1000));

    let browser, context;
    try {
        browser = await chromium.launch();
        context = await browser.newContext();
        const page = await context.newPage();

        // 1. Log in as admin
        await page.goto(`http://127.0.0.1:${port}/?login=1`);
        
        // Wait for and dismiss dialogs if any (like "database restored" notice)
        await page.waitForTimeout(500);
        const confirm = page.locator('.swal2-confirm');
        if (await confirm.count() && await confirm.isVisible().catch(() => false)) {
            await confirm.click({ timeout: 4000 }).catch(() => {});
            await page.waitForTimeout(250);
        }

        await page.fill('#landing-login-username', config.staff.admin.username);
        await page.fill('#landing-login-password', config.staff.admin.password);
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
            page.locator('#landing-login-password').press('Enter')
        ]);

        // 2. Go to Demand Forecast
        await page.goto(`http://127.0.0.1:${port}/components/report/predictions.php`);
        
        // Assert empty state
        await page.waitForSelector('h2:has-text("No Forecasts Available")');

        // 3. Click "Generate Now"
        await page.click('button:has-text("Generate Now")');

        // It should queue and redirect back or show a message.
        // Wait for page to reload/redirect.
        await page.waitForLoadState('networkidle');

        // 4. Read DB to verify ml_jobs was inserted
        const checkJobCode = `<?php
            require '${repoRoot.replace(/\\/g, '/')}/src/backend/bootstrap/app.php';
            $pdo = \\App\\Core\\Database::connection();
            $stmt = $pdo->query("SELECT * FROM ml_jobs ORDER BY job_id DESC LIMIT 1");
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode($job);
        `;
        const checkJobFile = path.join(require('node:os').tmpdir(), 'check_job_' + token + '.php');
        fs.writeFileSync(checkJobFile, checkJobCode);
        const jobResult = spawnSync(phpBinary, [checkJobFile], { cwd: repoRoot, env: process.env });
        if (jobResult.status !== 0) {
            throw new Error('checkJob script failed: ' + jobResult.stderr.toString());
        }
        const jobOutput = jobResult.stdout.toString().trim();
        const job = jobOutput ? JSON.parse(jobOutput) : null;
        if (!job || job.status !== 'queued') {
            throw new Error('Expected a queued ml_job, got ' + (job ? job.status : 'null'));
        }

        // 5. Simulate worker successfully processing the job
        const workerSimCode = `<?php
            require '${repoRoot.replace(/\\/g, '/')}/src/backend/bootstrap/app.php';
            $pdo = \\App\\Core\\Database::connection();
            $jobId = ${job.job_id};
            $pdo->exec("UPDATE ml_jobs SET status = 'succeeded' WHERE job_id = " . $jobId);
            $productId = ${config.products.countable.product_id};
            $stmt = $pdo->prepare("INSERT INTO demand_forecasts (job_id, product_id, forecast_origin, target_date, horizon_day, predicted_units, model_version, readiness_level) VALUES (?, ?, CURRENT_DATE, DATE_ADD(CURRENT_DATE, INTERVAL ? DAY), ?, ?, 'v1', 'High')");
            for ($i = 1; $i <= 28; $i++) {
                $stmt->execute([$jobId, $productId, $i, $i, 10]);
            }
        `;
        const workerSimFile = path.join(require('node:os').tmpdir(), 'worker_sim_' + token + '.php');
        fs.writeFileSync(workerSimFile, workerSimCode);
        const simResult = spawnSync(phpBinary, [workerSimFile], { cwd: repoRoot, env: process.env });
        if (simResult.status !== 0) {
            throw new Error('workerSim script failed: ' + simResult.stderr.toString());
        }

        // 6. Reload Demand Forecast
        await page.reload();
        await page.waitForSelector('table.data-table');
        
        // Assert sums (10 units * 7 days = 70, 14 days = 140, 28 days = 280)
        const rowText = await page.locator('table.data-table tbody tr').first().innerText();
        if (!rowText.includes('70') || !rowText.includes('140') || !rowText.includes('280')) {
            throw new Error('Forecast sums are incorrect in view: ' + rowText);
        }

        // 7. Click the product link to go to Product Investigation
        const productLink = page.locator('table.data-table tbody tr').first().locator('a');
        const href = await productLink.getAttribute('href');
        await page.goto(`http://127.0.0.1:${port}${href}`);
        
        // Assert we are on Product Investigation page
        await page.waitForSelector('h1:has-text("Product Investigation")');
        
        // Assert Daily Forecast table has 28 rows
        const dailyRows = page.locator('table.data-table').last().locator('tbody tr');
        const rowCount = await dailyRows.count();
        if (rowCount !== 28) {
            throw new Error('Expected 28 daily forecast rows, got ' + rowCount);
        }
        
        // Check that a row has predicted units = 10
        const firstDailyRowText = await dailyRows.first().innerText();
        if (!firstDailyRowText.includes('10')) {
            throw new Error('Daily forecast row does not contain predicted units 10: ' + firstDailyRowText);
        }

        // 8. Go to Replenishment Queue
        await page.goto(`http://127.0.0.1:${port}/components/inventory_management/replenishment_queue.php`);
        
        // Wait for page load
        await page.waitForSelector('h1:has-text("Replenishment Action Queue")');
        
        // Verify replenishment recommendation is present and uses 28-day logic (324 units)
        const queueItems = page.locator('.queue-item');
        const itemCount = await queueItems.count();
        if (itemCount === 0) {
            throw new Error('Replenishment queue is empty');
        }
        
        // Find the specific item we forecasted
        const targetItemText = await page.locator(`.queue-item:has-text("${config.products.countable.sku}")`).innerText();
        
        if (!targetItemText.includes('324 units')) {
            throw new Error('Replenishment queue does not factor in 28-day forecast correctly (expected 324 units): ' + targetItemText);
        }

        console.log('OK');

    } catch (e) {
        console.error(e);
        if (context) {
            const page = await context.pages()[0];
            const html = await page.content();
            console.error("HTML:", html.substring(0, 1000));
        }
        process.exitCode = 1;
    } finally {
        if (browser) await browser.close();
        server.kill();
        spawnSync(phpBinary, [fixtureScript, '--token', token, '--cleanup'], { cwd: repoRoot, env: process.env });
        try { fs.unlinkSync(checkJobFile); } catch (e) {}
        try { fs.unlinkSync(workerSimFile); } catch (e) {}
    }
})();
