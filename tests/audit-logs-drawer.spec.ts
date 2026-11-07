import { test, expect } from '@playwright/test';

test('opens audit record details in a right-side drawer and closes it', async ({ page }) => {
  await page.setContent(`
    <main class="audit-log-page">
      <table id="auditLogsTable">
        <tbody>
          <tr>
            <td>2026-11-06 07:00</td>
            <td>Admin</td>
            <td>Updated product</td>
            <td>Inventory</td>
            <td>Products</td>
            <td>42</td>
            <td>
              <button type="button" class="audit-details-trigger" aria-label="View details for Updated product">
                View details
              </button>
              <template class="audit-details-template">
                <dl>
                  <dt>IP Address</dt>
                  <dd>127.0.0.1</dd>
                  <dt>New Value</dt>
                  <dd>Price: 125</dd>
                </dl>
              </template>
            </td>
          </tr>
        </tbody>
      </table>
      <div id="auditDetailsDrawer" class="rm-drawer-overlay" aria-hidden="true">
        <aside class="rm-drawer" role="dialog" aria-modal="true" aria-labelledby="auditDetailsTitle">
          <header class="rm-drawer-header">
            <h2 id="auditDetailsTitle">Audit record details</h2>
            <button type="button" class="rm-close" data-close-drawer>Close</button>
          </header>
          <div class="rm-drawer-body" id="auditDetailsBody"></div>
        </aside>
      </div>
    </main>
  `);
  await page.addScriptTag({ path: 'src/frontend/assets/js/audit-log-drawer.js' });

  await page.getByRole('button', { name: 'View details for Updated product' }).click();
  await expect(page.locator('#auditDetailsDrawer')).toHaveClass(/open/);
  await expect(page.locator('#auditDetailsDrawer')).toHaveAttribute('aria-hidden', 'false');
  await expect(page.locator('#auditDetailsBody')).toContainText('Price: 125');

  await page.getByRole('button', { name: 'Close' }).click();
  await expect(page.locator('#auditDetailsDrawer')).not.toHaveClass(/open/);
  await expect(page.locator('#auditDetailsDrawer')).toHaveAttribute('aria-hidden', 'true');
});
