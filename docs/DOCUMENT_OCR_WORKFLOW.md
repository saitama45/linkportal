# Vendor Document OCR & Review Workflow

Vendors upload (or email) PDF/DOC/DOCX invoices, purchase orders, and quotations.
linkportal converts, OCRs, and extracts fields using admin-annotated templates;
admins validate results and hand documents off to ghelpdesk's **Accounting
Document Reviews** inbox. Decisions flow back automatically and vendors track
progress plus an AP payment-status snapshot in the portal.

## Processes per environment

| Process | Command | Notes |
|---|---|---|
| linkportal web | `php artisan serve` / IIS | |
| linkportal queue worker | `php artisan queue:work` | runs Convert/Extract/Submit jobs (DB queue) |
| linkportal scheduler | `php artisan schedule:work` (dev) / Task Scheduler `schedule:run` every minute (prod) | email polling + overdue sweep |
| OCR sidecar | `ocr-worker\.venv\Scripts\uvicorn.exe app.main:app --port 8077` | keep bound to 127.0.0.1; see `ocr-worker/README.md` |
| ghelpdesk web | as today | |
| ghelpdesk queue worker | `php artisan queue:work` | decision callbacks to linkportal |

Prod Windows: register the queue workers and uvicorn as services (NSSM) or
Task Scheduler at-startup tasks.

## One-time setup

1. **Migrations** (shared DB `tashelpdeskdb` — never bare `migrate:fresh`):
   - linkportal: `php artisan migrate --path=database/migrations/portal` and
     `php artisan migrate --path=database/migrations/2026_07_10_000002_add_document_processing_permissions.php`
   - ghelpdesk: `php artisan migrate --path=database/migrations/2026_07_10_000001_create_acct_document_reviews_tables.php`
     and `...000002_create_accounting_documents_permissions.php`
2. **Exception rules**: `php artisan db:seed --class=DocumentExceptionRuleSeeder`
3. **Integration tokens** (each app authenticates into the other):
   - In ghelpdesk: `php artisan integration:issue-token linkportal` → put token in
     linkportal `.env` as `GHELPDESK_API_TOKEN`, plus `GHELPDESK_URL=`.
   - In linkportal: `php artisan portal:issue-integration-token ghelpdesk` → put token in
     ghelpdesk `.env` as `LINKPORTAL_API_TOKEN`, plus `LINKPORTAL_URL=`.
   - linkportal Sanctum tokens live in `portal_personal_access_tokens` (NOT the shared
     `personal_access_tokens`, which belongs to ghelpdesk) — cross-app tokens can never authenticate.
4. **OCR sidecar**: install Tesseract + LibreOffice, `pip install -r ocr-worker/requirements.txt`,
   set `OCR_SERVICE_URL` in linkportal `.env`.
5. **Email intake** (optional): set `IMAP_HOST/PORT/ENCRYPTION/USERNAME/PASSWORD` in linkportal `.env`.
   Senders are matched exact-address first (vendor login email + `portal_vendor_intake_emails`),
   then by registered domain, else routed to the exception queue where "remember this sender" saves the mapping.

## Pipeline states

`received → converting → extracting → needs_validation → validated → sending →
pending_external_review → approved | returned | rejected`, with retryable failure
states `conversion_failed`, `extraction_failed`, `handoff_failed`, and `cancelled`.
Emailed documents without a matched vendor or classified type hold at `received`
with blocker exceptions until an admin classifies them.

## Key surfaces

- Vendor: Uploads (`/vendor/document-uploads`), Payment Status (`/vendor/accounts-payable`)
- Admin (linkportal): Document Intake inbox + validation screen (`/document-intake`),
  OCR Templates + visual annotator (`/document-templates`), Exceptions queue + rules (`/document-exceptions`),
  AP snapshot (`/accounts-payable`)
- Reviewer (ghelpdesk): Accounting Documents inbox (`/accounting-documents`), Monitoring sidebar section

## Integration contracts

- linkportal → ghelpdesk: `POST /api/accounting/document-reviews` (Sanctum bearer;
  idempotency_key `lp-doc-{id}-s{submission_count}`; payload includes validated fields,
  line items, confidences, warnings, 7-day signed file URL, callback URL)
- ghelpdesk → linkportal: `POST /api/integrations/ghelpdesk/document-review-decision`
  (decision approve/return/reject; remarks required unless approve; idempotent)
- Accounting (future) → linkportal: `POST /api/integrations/accounting/invoice-payment-status`
  upserts `portal_ap_invoice_statuses` (for_collection/processing/partially_paid/paid/on_hold/cancelled).
  Fully functional today; test with curl + a linkportal integration token.
- All calls (both directions) are audited in `portal_integration_calls`.

## Exception rules

Configured in `portal_document_exception_rules` (editable in the Exceptions screen):
missing_required_field, low_confidence, duplicate_invoice_no, po_mismatch, total_mismatch,
vendor_inactive, unsupported_file, failed_conversion, unmatched_email, missing_document_type,
missing_template, duplicate_file, failed_handoff, overdue_review. Blockers stop
validation/submission until resolved or waived (waive requires a note).
