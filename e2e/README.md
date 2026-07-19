# Link Portal — Browser QA Automation

Automated end-to-end testing with [Playwright](https://playwright.dev). This drives
a real browser through the app so you don't have to click through workflows by hand.

It's **self-contained** — its own `package.json` and dependencies, separate from the
Laravel app's build, and excluded from the Docker image. Nothing here affects prod.

---

## One-time setup

```bash
cd e2e
npm install            # installs Playwright
npm run setup          # downloads the Chromium browser it drives
cp .env.e2e.example .env.e2e   # then edit .env.e2e with real logins + base URL
```

`.env.e2e` holds the admin + vendor credentials and the app URL. It is **gitignored**
— never commit real credentials.

The app must be running (e.g. `php artisan serve` on the URL in `.env.e2e`).

---

## Running it

| Command | What it does |
|---|---|
| `npm run qa` | Read-only smoke, headless & fast. Never touches data. Best for CI / pre-deploy. |
| `npm run qa:watch` | Read-only smoke, **headed + slowed — watch it click through.** Best for demos. |
| `npm run qa:full` | **Full regression incl. the destructive workflow.** Backs up the DB first, runs everything, purges every test row after. |
| `npm run qa:full:watch` | Same as `qa:full` but headed + slowed so you can watch the whole workflow drive itself. |
| `npm run qa:ui` | Time-travel debugger — step through every action with screenshots. |
| `npm run qa:report` | Open the HTML report from the last run (screenshots + video on failures). |
| `npm run qa:codegen` | **Record a new test by clicking the app** — Playwright writes the code. |

### Safety model for `qa:full`

The destructive tests run against your **real local database**, so the suite protects it automatically:

1. **Backup first** — `global-setup.js` takes a full SQL Server `.bak` before any destructive test (path/creds in `.env.e2e`). A hard gate: if the backup can't run, the suite stops.
2. **Marked fixtures** — every test row is created through `php artisan portal:e2e …` and carries an `E2E-` marker.
3. **Auto-purge** — `global-teardown.js` deletes every marked row afterwards, pass or fail. Only `E2E-`-marked rows are ever touched.

If a run is ever interrupted, clean up manually with: `php artisan portal:e2e purge`

Run a single file: `npm run qa -- tests/po-to-pay.vendor.spec.js`
Run one project: `npm run qa -- --project=admin`

---

## What's covered

- **`tests/auth.setup.js`** — logs in once as staff/admin and once as vendor, saves each
  session so every other test starts already signed in (the app has two separate logins).
- **`tests/po-to-pay.admin.spec.js`** — read-only admin checks: cycle-time panel,
  "POs Awaiting Invoice" filter, an approved PO's fulfillment card, the PO-expiration
  config control.
- **`tests/po-to-pay.vendor.spec.js`** — read-only vendor checks: document type tabs,
  Purchase Orders filter, fulfillment badges, PO-number search.
- **`tests/full-cycle.workflow.spec.js`** — the core destructive workflow: vendor portal
  upload, emailed-attachment intake, PO over-billing blocker, and accounting approval via
  the decision webhook. Runs only under `npm run qa:full`.
- **`tests/scenarios.workflow.spec.js`** — a tour of *other* situations: accounting
  **returns** / **rejects** a document, an invoice with a **wrong PO number** (po_mismatch),
  a **partially invoiced** PO (with remaining balance), a **fully invoiced** PO, an invoice
  against an **expired PO** (temporarily enables the expiration policy, then resets it), and
  the **vendor's own fulfillment view**. Best watched with `npm run qa:demo`.
- **`tests/document-types.workflow.spec.js`** — coverage across document types: a vendor
  uploads an **Invoice** and a **Quotation**, and a **Quotation** runs through accounting
  approval (which, unlike an invoice, creates no payable). (Invoices are also exercised
  throughout the scenarios above — over-billing, matching, and all three decisions.)

The read-only tests are safe against any environment: they only navigate, filter, and
open panels. They never create or change data.

Fixtures for the destructive tests are created and cleaned up by the guarded
`php artisan portal:e2e {seed-po|seed-overbill|inject-email|seed-pending|token|purge}`
command (`app/Console/Commands/E2eSupport.php`) — it refuses to run in production and only
ever touches `E2E-`-marked rows.

---

## Scheduling (optional)

Point CI (GitHub Actions, etc.) or a cron job at `npm run qa` to catch regressions
automatically. The HTML report + trace/video artifacts make failures easy to diagnose
without reproducing by hand.
