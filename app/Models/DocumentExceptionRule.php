<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentExceptionRule extends Model
{
    protected $table = 'portal_document_exception_rules';

    protected $fillable = ['rule_key', 'label', 'enabled', 'severity', 'config'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'config' => 'array',
        ];
    }

    public function configValue(string $key, $default = null)
    {
        return data_get($this->config, $key, $default);
    }
}
