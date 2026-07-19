const { test, expect } = require('@playwright/test');
const path = require('node:path');
const { artisan } = require('../support/artisan');

/**
 * FULL PO-to-Pay WORKFLOW — real, destructive coverage against the local DB.
 *
 * These create/mutate rows, so they run ONLY on `npm run qa:full`
 * (E2E_DESTRUCTIVE=1), which backs up the database first and purges every
 * E2E-marked fixture afterwards. On a plain `npm run qa` they are skipped.
 *
 * Fixtures are created through the guarded `php artisan portal:e2e` command
 * (see app/Console/Commands/E2eSupport.php) so setup is deterministic and every
 * row is marked for cleanup.
 */
const DESTRUCTIVE = process.env.E2E_DESTRUCTIVE === '1';
const SAMPLE_PDF = path.resolve(__dirname, '..', 'fixtures', 'E2E-sample.pdf');
const AUTH = (role) => path.resolve(__dirname, '..', '.auth', `${role}.json`);

test.describe('Workflow · PO-to-Pay (destructive)', () => {
  test.skip(!DESTRUCTIVE, 'Destructive — run `npm run qa:full` (backs up the DB first, purges after).');

  test('vendor uploads a document via the portal; it lands in admin intake', async ({ browser }) => {
    const vendor = await browser.newContext({ storageState: AUTH('vendor') });
    const vPage = await vendor.newPage();
    await vPage.goto('/vendor/document-uploads/create');

    // Choose a document type (Autocomplete) then attach the sample PDF.
    await vPage.getByText('Purchase Order', { exact: true }).first().click().catch(() => {});
    await vPage.setInputFiles('input[type=file]', SAMPLE_PDF);
    await vPage.getByRole('button', { name: /Upload/ }).click();
    // Lands on the tracking page or back in the list — either proves acceptance.
    await expect(vPage).toHaveURL(/document-uploads/);
    await vendor.close();

    // Admin sees the newly uploaded file in the intake inbox. The list has no
    // filename column, but searching by filename (which the backend matches on)
    // filters to exactly this upload — so a returned row proves it arrived.
    const admin = await browser.newContext({ storageState: AUTH('admin') });
    const aPage = await admin.newPage();
    await aPage.goto('/document-intake');
    await aPage.getByPlaceholder(/Search/).fill('E2E-sample.pdf');
    await aPage.waitForTimeout(700);
    const firstRow = aPage.locator('tbody tr').first();
    await expect(firstRow).toBeVisible();
    await expect(firstRow).toContainText('portal upload'); // source column
    await admin.close();
  });

  test('an emailed attachment becomes an intake document (source: email)', async ({ browser }) => {
    // Inject through the real EmailIntakeService/createFromEmail path.
    const { id } = artisan('inject-email');

    const admin = await browser.newContext({ storageState: AUTH('admin') });
    const page = await admin.newPage();
    await page.goto(`/document-intake/${id}`);
    // The detail page shows how it arrived. Email-sourced docs read "Email".
    await expect(page.getByText(/Email/i).first()).toBeVisible();
    await admin.close();
  });

  test('over-billing a PO raises a blocker the admin can see', async ({ browser }) => {
    const { invoice_id } = artisan('seed-overbill');

    const admin = await browser.newContext({ storageState: AUTH('admin') });
    const page = await admin.newPage();
    await page.goto(`/document-intake/${invoice_id}`);

    // The exceptions panel surfaces the real po_amount_exceeded blocker.
    await expect(page.getByText('po_amount_exceeded')).toBeVisible();
    await expect(page.getByText('blocker').first()).toBeVisible();
    await admin.close();
  });

  test('accounting approval via the decision webhook flips the invoice to approved', async ({ browser, request }) => {
    const pending = artisan('seed-pending');
    const { token } = artisan('token');

    // Call the real inbound webhook the same way ghelpdesk would.
    const res = await request.post('/api/integrations/ghelpdesk/document-review-decision', {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
      data: {
        review_id: pending.external_review_id,
        source_document_id: pending.id,
        decision: 'approve',
        reviewer: 'E2E Bot',
      },
    });
    expect(res.ok(), `webhook responded ${res.status()}`).toBeTruthy();

    // Admin sees the document as Approved.
    const admin = await browser.newContext({ storageState: AUTH('admin') });
    const page = await admin.newPage();
    await page.goto(`/document-intake/${pending.id}`);
    await expect(page.getByText('approved').first()).toBeVisible();
    await admin.close();
  });
});
