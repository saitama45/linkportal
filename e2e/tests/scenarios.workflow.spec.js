const { test, expect } = require('@playwright/test');
const path = require('node:path');
const { artisan } = require('../support/artisan');

/**
 * A tour of DIFFERENT situations beyond the basic happy path — other accounting
 * decisions, several exception types, and the fulfillment progression. Great for
 * watching (`npm run qa:demo`) to get familiar with how each case behaves.
 *
 * Destructive: runs only under E2E_DESTRUCTIVE=1 (backs up first, purges after).
 */
const AUTH = (role) => path.resolve(__dirname, '..', '.auth', `${role}.json`);

/** POST the accounting decision webhook the way the external system would. */
async function decide(request, fixture, token, decision, remarks) {
  return request.post('/api/integrations/ghelpdesk/document-review-decision', {
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    data: { review_id: fixture.external_review_id, source_document_id: fixture.id, decision, remarks },
  });
}

async function openAsAdmin(browser, url) {
  const ctx = await browser.newContext({ storageState: AUTH('admin') });
  const page = await ctx.newPage();
  await page.goto(url);
  return { ctx, page };
}

test.describe('Scenarios · different situations (destructive)', () => {
  test.skip(process.env.E2E_DESTRUCTIVE !== '1', 'Destructive — run `npm run qa:full` or `npm run qa:demo`.');

  test('accounting RETURNS a document to the vendor with remarks', async ({ browser, request }) => {
    const fx = artisan('seed-pending');
    const { token } = artisan('token');
    expect((await decide(request, fx, token, 'return', 'Please attach the signed copy.')).ok()).toBeTruthy();

    const { ctx, page } = await openAsAdmin(browser, `/document-intake/${fx.id}`);
    await expect(page.getByText('returned').first()).toBeVisible();
    await ctx.close();
  });

  test('accounting REJECTS a document', async ({ browser, request }) => {
    const fx = artisan('seed-pending');
    const { token } = artisan('token');
    expect((await decide(request, fx, token, 'reject', 'Duplicate submission.')).ok()).toBeTruthy();

    const { ctx, page } = await openAsAdmin(browser, `/document-intake/${fx.id}`);
    await expect(page.getByText('rejected').first()).toBeVisible();
    await ctx.close();
  });

  test('an invoice with a wrong PO number is flagged (po_mismatch)', async ({ browser }) => {
    const { invoice_id } = artisan('seed-pomismatch');
    const { ctx, page } = await openAsAdmin(browser, `/document-intake/${invoice_id}`);
    await expect(page.getByText('po_mismatch')).toBeVisible();
    await ctx.close();
  });

  test('a partial invoice shows the PO as Partially Invoiced with a balance', async ({ browser }) => {
    const { po_id } = artisan('seed-partial');
    const { ctx, page } = await openAsAdmin(browser, `/document-intake/${po_id}`);
    await expect(page.getByText('Partially Invoiced')).toBeVisible();
    await expect(page.getByText('Invoices billed')).toBeVisible(); // the billed-invoice trail
    await ctx.close();
  });

  test('billing the full amount shows the PO as Fully Invoiced', async ({ browser }) => {
    const { po_id } = artisan('seed-full');
    const { ctx, page } = await openAsAdmin(browser, `/document-intake/${po_id}`);
    await expect(page.getByText('Fully Invoiced')).toBeVisible();
    await ctx.close();
  });

  test('with an expiration policy on, a late invoice is flagged (po_expired)', async ({ browser }) => {
    // Temporarily enables the 30-day policy; teardown/purge resets it to never.
    const { invoice_id } = artisan('seed-expired');
    const { ctx, page } = await openAsAdmin(browser, `/document-intake/${invoice_id}`);
    await expect(page.getByText('po_expired')).toBeVisible();
    await ctx.close();
  });

  test('vendor sees fulfillment status on their own purchase orders', async ({ browser }) => {
    artisan('seed-partial'); // an approved PO for the test vendor
    const ctx = await browser.newContext({ storageState: AUTH('vendor') });
    const page = await ctx.newPage();
    await page.goto('/vendor/document-uploads?document_type=purchase_order&status=approved');
    await expect(page.locator('tbody tr').first()).toBeVisible();
    await expect(
      page.locator('tbody tr').first().getByText(/Awaiting invoice|Partially invoiced|Fully invoiced|Expired/),
    ).toBeVisible();
    await ctx.close();
  });
});
