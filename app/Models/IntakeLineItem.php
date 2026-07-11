<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntakeLineItem extends Model
{
    protected $table = 'portal_intake_line_items';

    protected $fillable = [
        'intake_document_id', 'line_no',
        'description', 'quantity', 'uom', 'unit_price', 'line_total',
    ];

    protected function casts(): array
    {
        return [
            'intake_document_id' => 'integer',
            'line_no' => 'integer',
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'line_total' => 'decimal:2',
        ];
    }

    public function document()
    {
        return $this->belongsTo(IntakeDocument::class, 'intake_document_id');
    }
}
