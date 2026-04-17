<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class AppointmentLog extends Model
{
    protected $fillable = ['appointment_id', 'user_id', 'action', 'details', 'ip_address'];
    protected $casts = ['details' => 'array'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}