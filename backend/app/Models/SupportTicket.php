<?php

namespace App\Models;

use App\Traits\BelongsToLaboratory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SupportTicket extends Model
{
    use BelongsToLaboratory, HasUuids;

    public const STATUSES = ['abierto', 'en_curso', 'resuelto', 'cerrado'];
    public const PRIORITIES = ['baja', 'normal', 'alta'];

    protected $fillable = [
        'laboratory_id',
        'created_by',
        'assigned_to',
        'subject',
        'module',
        'priority',
        'status',
        'body',
        'admin_reply',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function laboratory()
    {
        return $this->belongsTo(Laboratory::class);
    }
}
