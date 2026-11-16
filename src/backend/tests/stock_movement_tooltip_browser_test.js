const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');

const frontend = path.resolve(__dirname, '../../frontend');
const phpPath = file => JSON.stringify(file.replaceAll('\\', '/'));
const html = execFileSync(process.env.PHP_BINARY || 'php', [], {encoding:'utf8', input:`<?php
require_once ${phpPath(path.resolve(__dirname, '../bootstrap/app.php'))};
include ${phpPath(path.join(frontend, 'components/inventory_management/stock_risk.php'))};
`});

(async () => {
    const browser = await chromium.launch({headless:true});
    const output = path.resolve('output/playwright/inventory-overview');
    fs.mkdirSync(output, {recursive:true});
    try {
        for (const [width,height] of [[1280,680],[390,600],[640,360]]) {
            const page = await browser.newPage({viewport:{width,height}});
            await page.setContent('<!doctype html><html><body><header class="global-topbar">Search products, reports, settings, and pages</header>' + html + '</body></html>');
            for (const file of ['theme.css','style.css','stock-risk.css']) {
                await page.addStyleTag({path:path.join(frontend,'assets/css',file)});
            }
            await page.addStyleTag({content:'.global-topbar{display:block!important;position:fixed;inset:0 0 auto;height:76px;background:white;z-index:2700}#stock-risk{margin-top:180px}'});
            await page.addScriptTag({path:path.join(frontend,'assets/js/info-tooltip.js')});
            const trigger = page.getByRole('button',{name:'About Stock Movement statuses',exact:true}).filter({visible:true});
            await trigger.focus();
            const tooltip = page.locator('#' + await trigger.getAttribute('aria-describedby'));
            const checkBounds = async () => {
                const bounds = await tooltip.boundingBox();
                assert.ok(bounds && bounds.y >= 84 && bounds.y + bounds.height <= height - 7,'All tooltip content stays below the fixed header and inside viewport: ' + JSON.stringify({width,height,bounds}));
                assert.ok(bounds.x >= 0 && bounds.x + bounds.width <= width,'Tooltip fits viewport width');
            };
            await checkBounds();
            assert.equal(await tooltip.locator('.sr-badge').count(),5);
            await tooltip.evaluate(el=>{el.scrollTop=el.scrollHeight;});
            await checkBounds();
            assert.ok(await tooltip.evaluate(el=>{
                const last=el.querySelector('.sr-status-definition:last-child').getBoundingClientRect();
                return last.bottom <= el.getBoundingClientRect().bottom;
            }),'Last status is reachable by scrolling');
            await page.screenshot({path:path.join(output,`stock-movement-tooltip-fit-${width}-${height}.png`)});
            await page.mouse.move(0,0);
            await trigger.press('Escape');
            await page.evaluate(()=>window.dispatchEvent(new Event('scroll')));
            assert.equal(await tooltip.isVisible(),false,'Escape stays dismissed after scroll');
            await trigger.click();
            assert.equal(await tooltip.isVisible(),true,'Tap reopens the guide');
            await page.close();
        }
        console.log('Stock Movement tooltip viewport: passed');
    } finally {
        await browser.close();
    }
})().catch(error=>{console.error(error);process.exitCode=1;});
