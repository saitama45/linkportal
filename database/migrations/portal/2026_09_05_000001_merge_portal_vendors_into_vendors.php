<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Collapses `portal_vendors` into the pre-existing `vendors` table so the portal
 * and the back office share one vendor identity.
 *
 * `vendors` is the survivor: tickets and the seven payment_* tables already carry
 * FKs to vendors.id, so those ids must not move. Portal accounts are folded in
 * (matched on email, then name, else inserted) and every portal child table's
 * vendor_id is remapped through the resulting id map.
 *
 * `portal_vendors` is deliberately left in place as a dormant record of the
 * pre-merge state — nothing reads it after this migration.
 */
return new class extends Migration
{
    /** parking range used to remap ids without colliding with unmoved ones */
    private const REMAP_OFFSET = 1000000000;

    /** portal tables whose vendor_id points at the old portal_vendors.id */
    private const CHILD_TABLES = [
        'portal_vendor_profiles' => 'vendor_id',
        'portal_vendor_contacts' => 'vendor_id',
        'portal_vendor_bank_accounts' => 'vendor_id',
        'portal_vendor_documents' => 'vendor_id',
        'portal_vendor_intake_emails' => 'vendor_id',
        'portal_intake_documents' => 'vendor_id',
        'portal_inbound_emails' => 'matched_vendor_id',
        'portal_document_templates' => 'vendor_id',
        'portal_ap_invoice_statuses' => 'vendor_id',
        'portal_invoices' => 'vendor_id',
        'portal_purchase_orders' => 'vendor_id',
        'portal_quotations' => 'vendor_id',
    ];

    public function up(): void
    {
        // `vendors` belongs to the back office (ghelpdesk), which shares this
        // database. On a standalone portal database (and in tests) it is absent,
        // so create it in its merged shape rather than assuming it exists.
        if (! Schema::hasTable('vendors')) {
            Schema::create('vendors', function (Blueprint $table) {
                $table->id();
                $table->string('code', 50)->nullable();
                $table->string('name');
                $table->string('contact_person')->nullable();
                $table->string('email')->nullable();
                $table->string('phone', 50)->nullable();
                $table->text('address')->nullable();
                $table->boolean('is_active')->default(true);
                $table->string('vendor_type', 50)->nullable();
                $table->unsignedBigInteger('company_id')->nullable()->index();
                $table->string('default_payment_mode', 100)->nullable();
                $table->text('default_payment_split')->nullable();
                $table->timestamps();
            });
        }

        // 1. Auth columns onto `vendors`. All nullable: the legacy back-office
        //    vendors have no portal login and must stay valid rows.
        Schema::table('vendors', function (Blueprint $table) {
            foreach ([
                'password' => fn () => $table->string('password')->nullable(),
                'status' => fn () => $table->string('status', 20)->nullable(),
                'email_verified_at' => fn () => $table->timestamp('email_verified_at')->nullable(),
                'remember_token' => fn () => $table->rememberToken(),
                'approved_by' => fn () => $table->unsignedBigInteger('approved_by')->nullable(),
                'approved_at' => fn () => $table->timestamp('approved_at')->nullable(),
                'last_login_at' => fn () => $table->timestamp('last_login_at')->nullable(),
                'created_by' => fn () => $table->unsignedBigInteger('created_by')->nullable(),
                'updated_by' => fn () => $table->unsignedBigInteger('updated_by')->nullable(),
                'deleted_at' => fn () => $table->softDeletes(),
            ] as $column => $add) {
                if (! Schema::hasColumn('vendors', $column)) {
                    $add();
                }
            }
        });

        // Portal self-registration leaves vendor_type unset; the back-office form
        // still requires it at validation level.
        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement('ALTER TABLE vendors ALTER COLUMN vendor_type nvarchar(50) NULL');
        }

        // Login is by email, so it must be unique — but only among rows that have
        // one. Most legacy vendors have NULL email, which a plain unique index
        // would reject on SQL Server (it treats NULLs as equal).
        DB::statement(
            'CREATE UNIQUE INDEX vendors_email_unique ON vendors (email) WHERE email IS NOT NULL'
        );

        if (! Schema::hasTable('portal_vendors')) {
            return;
        }

        // 2. Fold each portal account into `vendors`, building old id => new id.
        $map = [];

        foreach (DB::table('portal_vendors')->orderBy('id')->get() as $pv) {
            $existing = $pv->email
                ? DB::table('vendors')->where('email', $pv->email)->first()
                : null;

            // Fall back to an exact name match so a vendor already known to the
            // back office gains a login rather than becoming a duplicate row.
            $existing ??= DB::table('vendors')
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($pv->name)])
                ->whereNull('password')
                ->first();

            $auth = [
                'password' => $pv->password,
                'status' => $pv->status,
                'email_verified_at' => $pv->email_verified_at,
                'remember_token' => $pv->remember_token,
                'approved_by' => $pv->approved_by,
                'approved_at' => $pv->approved_at,
                'last_login_at' => $pv->last_login_at,
                'created_by' => $pv->created_by,
                'updated_by' => $pv->updated_by,
                'deleted_at' => $pv->deleted_at,
                'email' => $pv->email,
                'phone' => $pv->phone,
                'company_id' => $pv->company_id,
                'updated_at' => now(),
            ];

            if ($existing) {
                DB::table('vendors')->where('id', $existing->id)->update($auth);
                $map[$pv->id] = $existing->id;

                continue;
            }

            $map[$pv->id] = DB::table('vendors')->insertGetId($auth + [
                'code' => $pv->code,
                'name' => $pv->name,
                'vendor_type' => $pv->vendor_type,
                'is_active' => $pv->status === 'active' ? 1 : 0,
                'created_at' => $pv->created_at ?? now(),
            ]);
        }

        // 3. Repoint every portal child row at the surviving vendors.id.
        //    Two passes through a disjoint offset range: a direct old => new
        //    update could otherwise overwrite an id that has not been moved yet
        //    (e.g. 2 => 3 running before 3 => 22).
        foreach (self::CHILD_TABLES as $table => $column) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            foreach ($map as $oldId => $newId) {
                DB::table($table)->where($column, $oldId)
                    ->update([$column => $newId + self::REMAP_OFFSET]);
            }

            DB::table($table)->where($column, '>', self::REMAP_OFFSET)
                ->update([$column => DB::raw($column.' - '.self::REMAP_OFFSET)]);
        }
    }

    public function down(): void
    {
        // Intentionally not reversible: rolling back would mean dropping the auth
        // columns (discarding portal credentials) and unpicking the id remap.
        // Restore the pre-merge database backup instead.
        throw new RuntimeException(
            'merge_portal_vendors_into_vendors cannot be rolled back — restore the pre-merge backup.'
        );
    }
};
