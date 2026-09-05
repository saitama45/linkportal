<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Shared with the LINK HUB (ghelpdesk), which owns this table and its
 * migrations. Keep the columns and relations in step with the hub's model of
 * the same name; the portal only ever reads and writes through them.
 */
class VoucherSaleClaim extends Model
{
    protected $primaryKey = 'sale_key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['sale_key', 'voucher_redemption_id'];
}
