<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationCall extends Model
{
    protected $table = 'portal_integration_calls';

    protected $fillable = [
        'direction', 'system', 'endpoint', 'idempotency_key', 'subject_type',
        'subject_id', 'request_payload', 'response_payload', 'http_status',
        'status', 'attempts', 'last_attempt_at', 'error',
    ];

    protected function casts(): array
    {
        return [
            'subject_id' => 'integer',
            'request_payload' => 'array',
            'response_payload' => 'array',
            'http_status' => 'integer',
            'attempts' => 'integer',
            'last_attempt_at' => 'datetime',
        ];
    }

    public function subject()
    {
        return $this->morphTo();
    }
}
