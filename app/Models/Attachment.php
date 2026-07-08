<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attachment extends Model
{
    protected $table = 'portal_attachments';

    protected $fillable = [
        'attachable_type',
        'attachable_id',
        'file_path',
        'file_name',
        'mime_type',
        'file_size',
        'uploaded_by_type',
        'uploaded_by_id',
    ];

    public function attachable()
    {
        return $this->morphTo();
    }
}
