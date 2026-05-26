<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CloudSyncLog extends Model
{
    use HasUuids;

    protected $fillable = [
        'entity_type',
        'action',
        'entity_id',
        'status',
        'attempts',
        'last_error',
        'payload_hash',
        'payload',
        'synced_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'synced_at' => 'datetime',
    ];
}
