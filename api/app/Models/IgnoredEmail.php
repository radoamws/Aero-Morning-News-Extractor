<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IgnoredEmail extends Model
{
    protected $table = 't_ignored_emails';

    protected $fillable = [
        'message_id',
        'subject',
        'sender',
        'reason',
        'excerpt',
        'processed_at',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}