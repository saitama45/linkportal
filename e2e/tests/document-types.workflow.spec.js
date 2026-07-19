const { test, expect } = require('@playwright/test');
const path = require('node:path');
const { artisan } = require('../support/artisan');

/**
 * Coverage across all three document types. Invoices are already exercised by the
 * matching/decision scenarios; these add the vendor upload flow for an Invoice and
 * a Quotation, and a Quotation running through accounting (which, unlike an invoice,
 * creates no payable).
 *
 * Destructive: runs only under E2E_DESTRUCTIVE=1 (backs up first, purges after).
 */
const SAMPLE_PDF = path.resolve(__dirname, '..', 'fixtures', 'E2E-sample.pdf');
const AUTH = (role) => path.resolve(__dirname, '..', '.auth', `${role}.json`);

test.describe('Document types · invoice & quotation (destructive)', () => {
  test.skip(process.env.E2E_DESTRUCTIVE !== '1', 'Destructive — run `npm run qa:full` or `npm run qa:demo`.');

  for (const [typeLabel, dtype] of [['Invoice', 'invoice'], ['Quotation', 'quotation']]) {
    test(`vendor uploads a ${typeLabel} via the portal`, async ({ browser }) => {
      const ctx = await browser.newContext({ storageState: AUTH('vendor') });
      const page = await ctx.newPage();

      await page.goto('/vendor/document-uploads/create');
      await page.getByRole('button', { name: typeLabel, exact: true }).click(); // pick the type
      await page.setInputFiles('input[type=file]', SAMPLE_PDF);
      await page.getByRole('button', { name: /Upload/ }).click();
      await expect(page).toHaveURL(/document-uploads/);

      // It's filed under the right type in the vendor's own list.
      await page.goto(`/vendor/document-uploads?document_type=${dtype}`);
      await expect(page.locator('tbody tr td:nth-child(2)').first()).toHaveText(typeLabel);
      await ctx.close();
    });
  }

  test('a quotation runs through accounting approval (no payable created)', async ({ browser, request }) => {
    const fx = artisan('seed-pending', 'quotation');
    const { token } = artisan('token');

    const res = await request.post('/api/integrations/ghelpdesk/document-review-decision', {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
      data: { review_id: fx.external_review_id, source_document_id: fx.id, decision: 'approve', reviewer: 'E2E Bot' },
    });
    expect(res.ok(), `webhook responded ${res.status()}`).toBeTruthy();

    const ctx = await browser.newContext({ storageState: AUTH('admin') });
    const page = await ctx.newPage();
    await page.goto(`/document-intake/${fx.id}`);
    await expect(page.getByText('approved').first()).toBeVisible();
    await expect(page.getByText('Quotation').first()).toBeVisible(); // it's a quotation, not an invoice
    await ctx.close();
  });
});
