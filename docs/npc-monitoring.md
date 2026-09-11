# Cashier NPC Monitoring

Active vendor accounts with `vendor_type = Cashier` and an assigned `store_id`
can open NPC Monitoring from the portal navigation or dashboard. `/npc-statuses`
redirects to `/vendor/npc-statuses`; guests go to the vendor login.

## Source workflow reviewed

The implementation follows `ghelpdesk/app/Http/Controllers/NpcStatusController.php`
(`storeDownloadPayload`, `downloadStoreSeal`, `uploadStoreProof`,
`confirmStoreSeal`, `latestAttachmentPayloadForStore`, and finalization logic),
`resources/js/Components/NpcStatus/AssignedStoreSeals.vue`, the NPC models and
migrations, and `app/Services/NotificationService.php`.

The hub manages six steps: account registration, DPO profile, DPO registration,
NPC approval, store receiving, and store downloads/confirmation. Its store-user
view exposes only the final distribution workflow. The portal reuses that Vue
component and adapts its backend to the vendor guard and assigned store.

| Function | Portal behavior |
| --- | --- |
| Renewal history | Shows every NPC record assigned to the cashier's store, newest year first, with entity and validity dates. |
| DPO Seal / DPO Registration | Uses the latest entity-wide attachment within the record's validity year. |
| CCTV Seal | Uses only an attachment explicitly assigned to that store and validity year; no entity or other-store fallback. |
| Download | Checks access and file existence, records the first download, then streams the document. Subsequent downloads keep the original receipt. |
| Hub notification | First download creates the same database activity notification for staff with `npc_status.view` or `npc_status.edit`. |
| Proof of use | One file per renewal, store, and document type. Re-upload replaces that proof. PDF, JPG/JPEG, PNG, WebP, GIF, BMP, HEIC, and HEIF match the hub's validation. |
| Proof download | Cashiers can download their own store's uploaded proof. |
| Confirmation | Displays the hub's confirmation state. Only hub staff can confirm or unconfirm; no portal confirmation route exists. |
| Workflow completion | Hub staff still require all three downloads, proofs, and confirmations for every assigned store before completing step 6. |

The hub's upload validation maximum is retained at 1,024,000 KB. PHP and web
server upload/body limits may impose a lower maximum.

The hub allows replacement proofs even after confirmation; the portal preserves
that behavior. Uploading or downloading never marks a document confirmed or
changes the renewal workflow. Administrative fields, registration credentials,
backup codes, payment editing, and store assignment controls are not included in
the cashier payload.

## Shared data and files

The portal uses the existing `npc_statuses`, `npc_status_store`,
`npc_status_attachments`, `npc_seal_receipts`, and `npc_store_proofs` tables.
The hub owns their migrations; no duplicate production migrations or copied
business records are needed.

`filesystems.disks.npc` must point to the **same directory** as the hub's public
disk. Locally it defaults to `../ghelpdesk/storage/app/public`. For a deployment
with a different directory layout, set `NPC_SHARED_STORAGE_PATH` to the absolute
path of that directory or shared volume, with read/write access for the portal.
Both apps must use the same database and shared storage volume. A remote hub
requires a shared mount; this module does not transfer files over its API.

Shared `downloaded_by` and `uploaded_by` columns refer to hub `users`, whereas
cashiers are `vendors`. Portal actions leave those user IDs null and record the
vendor in `portal_audit_logs`. Download notifications also include
`actor_type = vendor`, `actor_vendor_id`, and the cashier's name. Existing hub
user attribution on an earlier download is preserved.

## Integration safeguards

- Every file action requires the active Cashier guard, its assigned store, and
  that store's membership in the selected NPC renewal.
- Download selection uses the same validity-year filter as the displayed cards.
  This closes a mismatch in the source download endpoint, which did not filter
  the attachment year even though its page did.
- Missing files do not create download receipts or notifications.
- A replacement proof is stored before updating the database; failed database
  writes remove the new file and preserve the previous proof. The old file is
  removed only after the database transaction succeeds.
- Parent-record locks serialize portal receipt/proof writes. The database's
  unique constraints remain authoritative across the two applications.
- The frontend clears the file input after an attempt so the same file can be
  selected again, and displays upload/download validation errors.

`tests/Feature/VendorNpcStatusTest.php` provides regression coverage using only
SQLite in memory, fake NPC storage, and fake notifications.
