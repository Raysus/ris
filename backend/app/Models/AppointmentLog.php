<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class AppointmentLog extends Model
{
    use HasUuids;
    protected $fillable = ['appointment_id', 'user_id', 'action', 'details', 'ip_address'];
    protected $casts = ['details' => 'array'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}