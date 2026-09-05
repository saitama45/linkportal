<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Models\VoucherRedemption;
use App\Models\VoucherSaleClaim;
use App\Models\VoucherVerificationAttempt;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The portal's half of the LINK HUB's VoucherService: verify a code, and apply
 * it as payment. Batch creation, activation, printing, voiding and export stay
 * in the hub — a cashier accepts vouchers, they do not administer them.
 *
 * The only substantive difference from the hub's implementation is the actor:
 * a portal cashier has no `users` row, so `redeemed_by` / `verified_by` stay
 * null and `cashier_vendor_id` records who took the payment instead.
 */
class CampaignVoucherService
{
    public function normalizeCode(string $code): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim($code)) ?? '');
    }

    public function verify(string $rawCode, int $cashierVendorId, ?int $storeId, ?int $companyId): array
    {
        $code = $this->normalizeCode($rawCode);

        $voucher = Voucher::with([
            'batch.company:id,name,code',
            'activeRedemption.customer:id,name,phone,email',
            'activeRedemption.store:id,code,name',
            'activeRedemption.cashier:id,name',
            'activeRedemption.cashierVendor:id,name',
        ])->where('code', $code)->first();

        $result = 'invalid';
        if ($voucher && (int) $voucher->batch->company_id === $companyId) {
            $result = match (true) {
                $voucher->status === 'void' => 'void',
                $voucher->status === 'used' => 'already_used',
                default => $voucher->batch->effectiveStatus(),
            };
        } else {
            // A batch belonging to another entity is not this cashier's to see,
            // so it reads as invalid rather than leaking that it exists.
            $voucher = null;
        }

        VoucherVerificationAttempt::create([
            'voucher_id' => $voucher?->id,
            'scanned_code' => mb_substr($code, 0, 255),
            'result' => $result,
            'store_id' => $storeId,
            'cashier_vendor_id' => $cashierVendorId,
            'verified_at' => now(),
        ]);

        return [
            'result' => $result,
            'message' => $this->resultMessage($result),
            'voucher' => $voucher ? [
                'id' => $voucher->id,
                'code' => $voucher->code,
                'status' => $voucher->status,
                'value' => $voucher->batch->face_value,
                'batch' => [
                    'id' => $voucher->batch->id,
                    'title' => $voucher->batch->title,
                    'partner_name' => $voucher->batch->partner_name,
                    'claim_starts_on' => $voucher->batch->claim_starts_on?->format('Y-m-d'),
                    'claim_ends_on' => $voucher->batch->claim_ends_on?->format('Y-m-d'),
                ],
                'redemption' => $voucher->activeRedemption ? [
                    'id' => $voucher->activeRedemption->id,
                    'customer' => $voucher->activeRedemption->customer,
                    'store' => $voucher->activeRedemption->store,
                    'cashier' => $voucher->activeRedemption->cashier,
                    'cashier_vendor' => $voucher->activeRedemption->cashierVendor,
                    'receipt_number' => $voucher->activeRedemption->receipt_number,
                    'sale_date' => $voucher->activeRedemption->sale_date?->format('Y-m-d'),
                    'gross_sale_total' => $voucher->activeRedemption->gross_sale_total,
                    'applied_amount' => $voucher->activeRedemption->applied_amount,
                    'redeemed_at' => $voucher->activeRedemption->redeemed_at?->toIso8601String(),
                ] : null,
            ] : null,
        ];
    }

    public function redeem(array $data, int $cashierVendorId, int $companyId): VoucherRedemption
    {
        return DB::transaction(function () use ($data, $cashierVendorId, $companyId) {
            $voucher = Voucher::with('batch')
                ->where('code', $this->normalizeCode($data['code']))
                ->lockForUpdate()
                ->first();

            if (! $voucher || (int) $voucher->batch->company_id !== $companyId) {
                throw ValidationException::withMessages(['code' => 'That voucher code is invalid.']);
            }

            // Re-read the batch under a lock: its status and claim window decide
            // whether this code may still be accepted.
            $voucher->setRelation('batch', VoucherBatch::query()->lockForUpdate()->findOrFail($voucher->voucher_batch_id));

            if ($voucher->status !== 'issued' || $voucher->batch->effectiveStatus() !== 'active') {
                throw ValidationException::withMessages(['code' => $this->resultMessage(
                    $voucher->status === 'used'
                        ? 'already_used'
                        : ($voucher->status === 'void' ? 'void' : $voucher->batch->effectiveStatus())
                )]);
            }

            $customer = $this->resolveCustomer($data);
            $gross = round((float) $data['gross_sale_total'], 2);
            $face = round((float) $voucher->batch->face_value, 2);
            $applied = min($gross, $face);

            try {
                $redemption = VoucherRedemption::create([
                    'voucher_id' => $voucher->id,
                    'customer_id' => $customer->id,
                    'store_id' => $data['store_id'],
                    'receipt_number' => trim($data['receipt_number']),
                    'sale_date' => $data['sale_date'],
                    'gross_sale_total' => $gross,
                    'applied_amount' => $applied,
                    'forfeited_amount' => max(0, $face - $applied),
                    'redeemed_at' => now(),
                    // No `redeemed_by`: the actor is a portal cashier, not a
                    // back-office user.
                    'cashier_vendor_id' => $cashierVendorId,
                ]);

                // The unique sale key is what stops two vouchers being applied
                // to the same receipt.
                VoucherSaleClaim::create([
                    'sale_key' => self::saleKey((int) $data['store_id'], $data['sale_date'], $data['receipt_number']),
                    'voucher_redemption_id' => $redemption->id,
                ]);
            } catch (QueryException) {
                throw ValidationException::withMessages([
                    'receipt_number' => 'A voucher has already been applied to this store, sale date, and receipt.',
                ]);
            }

            $voucher->update(['status' => 'used']);

            return $redemption->load(['voucher.batch', 'customer', 'store', 'cashierVendor']);
        });
    }

    public static function saleKey(int $storeId, string $saleDate, string $receipt): string
    {
        return hash('sha256', $storeId.'|'.$saleDate.'|'.mb_strtoupper(trim($receipt)));
    }

    private function resolveCustomer(array $data): Customer
    {
        if (! empty($data['customer_id'])) {
            $customer = Customer::where('is_active', true)->find($data['customer_id']);

            if (! $customer) {
                throw ValidationException::withMessages(['customer_id' => 'Select an active customer.']);
            }

            return $customer;
        }

        $phone = trim($data['new_customer_phone'] ?? '');
        $existing = Customer::where('phone', $phone)->where('is_active', true)->first();

        if ($existing) {
            return $existing;
        }

        return Customer::create([
            'name' => trim($data['new_customer_name']),
            'phone' => $phone,
            'email' => $data['new_customer_email'] ?? null,
            'is_active' => true,
        ]);
    }

    private function resultMessage(string $result): string
    {
        return match ($result) {
            'active' => 'Verified — this voucher is valid and ready to apply as payment.',
            'already_used' => 'This voucher has already been used.',
            'void' => 'This voucher was voided and cannot be used.',
            'draft' => 'This voucher batch has not been activated.',
            'not_yet_valid' => 'This voucher is not yet within its claim period.',
            'expired' => 'This voucher has expired.',
            'suspended' => 'This voucher batch is temporarily suspended.',
            'cancelled' => 'This voucher batch was cancelled.',
            default => 'That voucher code is invalid.',
        };
    }
}
