<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToLaboratory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Hl7Message extends Model
{
    use BelongsToLaboratory, HasUuids;
    protected $fillable = [
        'laboratory_id',
        'appointment_id',
        'message_control_id',
        'placer_order_id',
        'message_type',
        'direction',
        'raw_message',
        'status',
        'error_log',
    ];

    public function appointment()
    {
        return $this->belongsTo(Appointment::class);
    }
}