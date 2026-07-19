const { test, expect } = require('@playwright/test');

/**
 * Vendor-side, READ-ONLY smoke. Navigates the vendor's own document views —
 * tabs, filters, PO-number search, fulfillment badges — without creating data.
 */
test.describe('Vendor · Documents (read-only)', () => {
  test('Document Uploads has type tabs and a status filter', async ({ page }) => {
    await page.goto('/vendor/document-uploads');
    for (const tab of ['All', 'Invoices', 'Purchase Orders', 'Quotations']) {
      await expect(page.getByRole('button', { name: tab, exact: true })).toBeVisible();
    }
  });

  test('Purchase Orders tab filters to PO documents', async ({ page }) => {
    await page.goto('/vendor/document-uploads');
    await page.getByRole('button', { name: 'Purchase Orders', exact: true }).click();
    await expect(page).toHaveURL(/document_type=purchase_order/);

    const typeCells = page.locator('tbody tr td:nth-child(2)');
    const count = await typeCells.count();
    for (let i = 0; i < count; i++) {
      await expect(typeCells.nth(i)).toHaveText('Purchase Order');
    }
  });

  test('Approved POs show a fulfillment badge', async ({ page }) => {
    await page.goto('/vendor/document-uploads?document_type=purchase_order&status=approved');
    const firstRow = page.locator('tbody tr').first();

    // Data-dependent: only assert the badge if there's an approved PO to show.
    if (await firstRow.count()) {
      await expect(firstRow).toBeVisible();
      await expect(
        firstRow.getByText(/Awaiting invoice|Partially invoiced|Fully invoiced|Expired/),
      ).toBeVisible();
    }
  });

  test('Search matches by PO number', async ({ page }) => {
    await page.goto('/vendor/document-uploads?document_type=purchase_order');
    const firstDocNo = page.locator('tbody tr td:nth-child(4)').first();
    if (!(await firstDocNo.count())) test.skip(true, 'No PO rows to search');

    const poNumber = (await firstDocNo.innerText()).trim();
    const fragment = poNumber.slice(0, 6);
    await page.getByPlaceholder(/Search/).fill(fragment);
    await page.waitForTimeout(600); // debounced search
    await expect(page.locator('tbody tr td:nth-child(4)').first()).toContainText(fragment);
  });
});
