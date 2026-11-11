const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');
const frontend = path.resolve(__dirname, '../../frontend');
const source = fs.readFileSync(path.join(frontend, 'components/system_administrator/audit_logs.php'), 'utf8');
const start = source.indexOf('<form method="GET" class="audit-history-filters"');
const form = source.slice(start, source.indexOf('</form>', start) + 7);
const script = source.slice(source.indexOf('            var historyFilters ='), source.indexOf('            var reviewForm ='));
const output = fs.mkdtempSync(path.join(os.tmpdir(), 'retailmind-audit-filters-'));
(async () => {
    const browser = await chromium.launch({headless:true});
    try {
        for (const role of ['admin', 'super_admin']) {
            const markup = execFileSync('php', [], {cwd:path.resolve(__dirname,'../../..'),encoding:'utf8',input:`<?php
                require_once 'src/backend/bootstrap/app.php';
                function app_url($path) { return $path; }
                $canViewPlatformAudit = ${role === 'super_admin' ? 'true' : 'false'};
                $userFilter = '4'; $dateFrom = '2026-11-01'; $dateTo = '2026-11-11';
                $categoryFilter = ''; $moduleFilter = 'Inventory'; $actionFilter = 'Physical stock reconciliation';
                $modules = ['Inventory', 'Sales'];
                $actions = ['Physical stock reconciliation', 'Product created'];
                ?>${form}`});
            for (const width of [1440,390]) {
                const page = await browser.newPage({viewport:{width,height:900}});
                await page.setContent(`<html><head><meta name="viewport" content="width=device-width, initial-scale=1"></head><body class="audit-log-page"><main class="main-content"><section class="dashboard-section audit-table-card"><h2>Record History</h2>${markup}</section></main></body></html>`);
                for (const name of ['style','theme','audit-logs']) await page.addStyleTag({path:path.join(frontend,`assets/css/${name}.css`)});
                await page.evaluate(() => {
                    window.submissions=[];
                    document.getElementById('auditHistoryFilters').addEventListener('submit', event => {
                        event.preventDefault();
                        submissions.push(Object.fromEntries(new FormData(event.target)));
                    });
                });
                await page.addScriptTag({content:script});
                assert.equal(await page.getByLabel('Action',{exact:true}).inputValue(),'Physical stock reconciliation');
                assert.equal(await page.getByLabel('Category',{exact:true}).count(),role === 'super_admin' ? 1 : 0,'Store audit does not offer platform categories');
                await page.getByLabel('Action',{exact:true}).selectOption('Product created');
                const selected = await page.evaluate(() => submissions.at(-1));
                assert.equal(selected.action,'Product created','Action selection submits immediately');
                assert.equal(selected.user_id,'4');
                assert.equal(selected.date_from,'2026-11-01');
                assert.equal(selected.date_to,'2026-11-11');
                await page.getByLabel('Module',{exact:true}).selectOption('Sales');
                assert.equal(await page.evaluate(() => submissions.at(-1).action),'','Changing module clears the prior action');
                if (role === 'super_admin') {
                    await page.getByLabel('Action',{exact:true}).selectOption('Product created');
                    await page.getByLabel('Category',{exact:true}).selectOption('security');
                    assert.equal(await page.evaluate(() => submissions.at(-1).category),'security');
                    assert.equal(await page.evaluate(() => submissions.at(-1).module),'','Changing category clears the prior module');
                    assert.equal(await page.evaluate(() => submissions.at(-1).action),'','Changing category clears the prior action');
                }
                for (const field of await page.locator('.audit-history-filters select').all()) {
                    const bounds = await field.boundingBox();
                    assert(bounds.x >= 0 && bounds.x+bounds.width <= width,`${width}: filters fit`);
                }
                assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),'No page overflow');
                await page.screenshot({path:path.join(output,`${role}-${width}.png`),fullPage:true});
                await page.close();
            }
        }
        console.log(`Audit history filters: passed; screenshots: ${output}`);
    } finally {await browser.close();}
})().catch(error => {console.error(error);process.exitCode=1;});
