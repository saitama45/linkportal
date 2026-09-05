<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class Vendor extends Authenticatable implements MustVerifyEmail
{
    use Notifiable, SoftDeletes;

    // Shared with the back office (ghelpdesk): one vendor identity, one table.
    protected $table = 'vendors';

    /** Portal slug => the label the back office stores on `vendors.vendor_type`. */
    public const VENDOR_TYPE_LABELS = [
        'supplier' => 'Supplier',
        'service_provider' => 'Service Provider',
        'contractor' => 'Contractor',
        'consultant' => 'Consultant',
        'logistics' => 'Logistics / Forwarder',
        'cashier' => self::TYPE_CASHIER,
    ];

    /**
     * Selectable vendor types, in the order the back office lists them.
     *
     * Vendor types are managed at runtime from the back office /vendors modal
     * (`reference_options`, type = `vendor_type`), so this must never be read
     * from VENDOR_TYPE_LABELS: a type an admin adds there (Cashier, say) would
     * otherwise be missing from portal registration. `portal_reference_options`
     * is the fallback for a portal-only database, the constant for neither.
     *
     * @return array<int, string>
     */
    public static function types(): array
    {
        foreach (['reference_options', 'portal_reference_options'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $labels = DB::table($table)
                ->where('type', 'vendor_type')
                ->when(
                    Schema::hasColumn($table, 'is_active'),
                    fn ($q) => $q->where('is_active', true)
                )
                ->orderBy('sort_order')
                ->orderBy('label')
                ->pluck('label')
                ->all();

            if ($labels) {
                return $labels;
            }
        }

        return array_values(self::VENDOR_TYPE_LABELS);
    }

    /**
     * A Cashier is not a supplier: it is a store till running the Campaigns
     * (loyalty stamps) module for the single store in `store_id`, which the
     * back office assigns on /vendors.
     */
    public const TYPE_CASHIER = 'Cashier';

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'email',
        'password',
        'phone',
        'address',
        'contact_person',
        'vendor_type',
        'store_id',
        'status',
        'is_active',
        'email_verified_at',
        'approved_by',
        'approved_at',
        'last_login_at',
        'created_by',
        'updated_by',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'approved_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /**
     * The portal picks vendor types as slugs ('service_provider') while the back
     * office stores and validates human labels ('Service Provider') on this same
     * shared column. Normalise on write so a portal registration is editable in
     * the back office instead of failing its `in:` rule.
     */
    protected function vendorType(): Attribute
    {
        return Attribute::set(function (?string $value) {
            if ($value === null || $value === '') {
                return null;
            }

            // Already a managed label ("Cashier", "Logistics / Forwarder") —
            // store it verbatim, or Str::headline would mangle the punctuation.
            if (in_array($value, self::types(), true)) {
                return $value;
            }

            return self::VENDOR_TYPE_LABELS[strtolower($value)] ?? Str::headline($value);
        });
    }

    /**
     * Statuses that refuse a sign-in outright. `pending` is deliberately absent:
     * a registrant must be able to sign in to complete their profile and upload
     * accreditation documents — that is exactly what the approver reviews.
     */
    public const BLOCKED_LOGIN_STATUSES = ['rejected', 'suspended', 'deactivated'];

    /** True when this account runs the Campaigns module rather than trading. */
    public function isCashier(): bool
    {
        return $this->vendor_type === self::TYPE_CASHIER;
    }

    /** The store a cashier's loyalty activity is booked against. */
    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isLoginBlocked(): bool
    {
        return in_array($this->status, self::BLOCKED_LOGIN_STATUSES, true);
    }

    /** Point the reset link at the portal's screen, not the staff one. */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new \App\Notifications\VendorResetPassword($token));
    }

    /**
     * Back-office-only vendors (the legacy reference list) have no password and
     * cannot sign in to the portal.
     */
    public function hasPortalAccess(): bool
    {
        return $this->password !== null;
    }

    public function scopeWithPortalAccess($query)
    {
        return $query->whereNotNull('password');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function profile()
    {
        return $this->hasOne(VendorProfile::class);
    }

    public function contacts()
    {
        return $this->hasMany(VendorContact::class);
    }

    public function bankAccounts()
    {
        return $this->hasMany(VendorBankAccount::class);
    }

    public function documents()
    {
        return $this->hasMany(VendorDocument::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function quotations()
    {
        return $this->hasMany(Quotation::class);
    }
}
