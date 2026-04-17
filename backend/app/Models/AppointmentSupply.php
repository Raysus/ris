<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class AppointmentSupply extends Model
{
    protected $fillable = ['appointment_id', 'supply_id', 'quantity', 'price_charged'];
    public function supply()
    {
        return $this->belongsTo(Supply::class);
    }
}