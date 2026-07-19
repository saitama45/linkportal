const { test, expect } = require('@playwright/test');

/**
 * Admin-side, READ-ONLY smoke of the PO-to-Pay workflow surfaces we built.
 * These only navigate, filter, and open panels — no data is created or mutated,
 * so they are safe to run against any environment. Anything that would write
 * (validating a doc, saving a rule) lives in *.workflow.spec.js instead.
 */
test.describe('Admin · PO-to-Pay (read-only)', () => {
  test('Document Intake shows Cycle Times and the awaiting-invoice queue', async ({ page }) => {
    await page.goto('/document-intake');

    // Cycle-time panel with all four metrics.
    await expect(page.getByText('Cycle Times')).toBeVisible();
    for (const label of ['Intake → Validated', 'Review Turnaround', 'Upload → Approved', 'PO → First Invoice']) {
      await expect(page.getByText(label)).toBeVisible();
    }

    // "POs Awaiting Invoice" card toggles the filter (a GET — non-destructive).
    const card = page.getByRole('button', { name: /POs Awaiting Invoice/ });
    await expect(card).toBeVisible();
    await card.click();
    await expect(page).toHaveURL(/awaiting_invoice=1/);
    await card.click();
    await expect(page).not.toHaveURL(/awaiting_invoice=1/);
  });

  test('Filtering to approved POs shows PO rows only', async ({ page }) => {
    await page.goto('/document-intake?document_type=purchase_order&status=approved');
    // Every visible Type cell should read "PO" (the admin list abbreviates it).
    const typeCells = page.locator('tbody tr td:nth-child(3)');
    const count = await typeCells.count();
    for (let i = 0; i < count; i++) {
      await expect(typeCells.nth(i)).toHaveText(/PO/);
    }
  });

  test('An approved PO detail shows the PO Fulfillment card', async ({ page }) => {
    await page.goto('/document-intake?document_type=purchase_order&status=approved');
    const firstRow = page.locator('tbody tr').first();
    await expect(firstRow).toBeVisible();
    // Open the row's detail (the eye / track action).
    await firstRow.getByRole('link').last().click();
    await expect(page.getByText('PO Fulfillment')).toBeVisible();
    // The three-figure reconciliation tiles.
    for (const label of ['PO Total', 'Invoiced', 'Remaining']) {
      await expect(page.getByText(label, { exact: true })).toBeVisible();
    }
  });

  test('Exception Rules exposes the PO expiration control (not saved)', async ({ page }) => {
    await page.goto('/document-exceptions');
    await page.getByRole('button', { name: 'Rules' }).click();
    await expect(page.getByText('Exception Rules')).toBeVisible();

    // The purpose-built expiration config, defaulting to "never".
    await expect(page.getByText('Expire approved POs after')).toBeVisible();
    await expect(page.getByText('Leave blank to keep POs open indefinitely.')).toBeVisible();

    // Close WITHOUT changing anything (read-only).
    await page.getByRole('button', { name: 'Close' }).click();
  });
});
