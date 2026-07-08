<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Audit Trail Toggle
    |--------------------------------------------------------------------------
    |
    | Set this to false to completely stop recording activity logs.
    |
    */
    'enabled' => env('AUDIT_TRAIL_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Retention Policy (in months)
    |--------------------------------------------------------------------------
    |
    | Activity logs older than this number will be pruned.
    | Default: 6 months. Options could be 36 (3 years) or 60 (5 years).
    |
    */
    'retention_months' => env('AUDIT_RETENTION_MONTHS', 6),
];
