<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class AppointmentSupply extends Model
{
    use HasUuids;
    protected $fillable = ['appointment_id', 'supply_id', 'quantity', 'price_charged'];
    public function supply()
    {
        return $this->belongsTo(Supply::class);
    }
}