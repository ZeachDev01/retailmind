'use strict';
// Add User password field must give the same colored criteria feedback as the
// reset/change password screen. Renders the real modal markup (PHP stripped) with
// the shared criteria script, then types passwords and reads the chip/bar state.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require('playwright');

const frontend = path.resolve(__dirname, '../../frontend');
const source = fs.readFileSync(path.join(frontend, 'components/user_manager/modals/add_user_modal.php'), 'utf8');
const modal = source.replace(/<\?(?:php|=)[\s\S]*?\?>/g, '');

(async () => {
    const browser = await chromium.launch({headless: true});
    try {
        const page = await browser.newPage();
        await page.setContent('<body class="manage-users-page">' + modal + '</body>');
        await page.addStyleTag({path: path.join(frontend, 'assets/css/style.css')});
        await page.addScriptTag({path: path.join(frontend, 'assets/js/password-criteria.js')});
        await page.evaluate(() => document.getElementById('userModalOverlay').classList.add('open'));

        const input = page.locator('#createUserPassword');
        const chip = rule => page.locator(`#createPasswordCriteria [data-rule="${rule}"]`);
        const validChips = () => page.locator('#createPasswordCriteria .cp-req-chip.valid').count();
        const activeBars = cls => page.locator(`#createPasswordCriteria [data-strength-bar].${cls}`).count();

        for (const rule of ['len', 'upper', 'lower', 'num']) {
            assert.equal(await chip(rule).count(), 1, `add-user password must show a "${rule}" criteria chip`);
        }
        assert.equal(await validChips(), 0, 'criteria chips start unmet before typing');

        await input.fill('abcdefgh');
        assert.equal(await chip('len').evaluate(node => node.classList.contains('valid')), true, '8 characters turns the length chip green');
        assert.equal(await chip('lower').evaluate(node => node.classList.contains('valid')), true, 'lowercase turns the lowercase chip green');
        assert.equal(await chip('upper').evaluate(node => node.classList.contains('valid')), false, 'missing uppercase leaves its chip unmet');
        assert.equal(await chip('num').evaluate(node => node.classList.contains('valid')), false, 'missing number leaves its chip unmet');
        assert.equal(await activeBars('active-fair'), 2, 'two met criteria fill two fair bars');
        assert.equal(await activeBars('active-weak'), 0, 'two met criteria never use the weak color');

        await input.fill('Abcdefg1');
        assert.equal(await validChips(), 4, 'a policy-compliant password turns every criteria chip green');
        assert.equal(await activeBars('active-strong'), 4, 'a policy-compliant password fills the meter green');

        await input.fill('Abcdefg');
        assert.equal(await chip('len').evaluate(node => node.classList.contains('valid')), false, '7 characters leaves the length chip unmet');
        assert.equal(await validChips(), 2, 'seven characters satisfies the two character-class chips only');

        await input.fill('');
        assert.equal(await validChips(), 0, 'clearing the password resets every chip');
        assert.equal(await page.locator('#createPasswordCriteria [data-strength-bar][class*="active-"]').count(), 0, 'clearing the password resets the meter');

        console.log('Add User password criteria: passed (chips and meter track the shared policy)');
    } finally {
        await browser.close();
    }
})().catch(error => {
    console.error(error);
    process.exit(1);
});
