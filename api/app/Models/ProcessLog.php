<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcessLog extends Model
{
    protected $table = 't_process_logs';

    protected $fillable = [
        'process_type',
        'status',
        'source',
        'news_id',
        'email_message_id',
        'message',
        'details',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
