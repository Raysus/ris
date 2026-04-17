<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class SupplyPackItem extends Model
{
    public $timestamps = false; // Esta tabla pivot no necesita timestamps
    protected $fillable = ['supply_pack_id', 'supply_id', 'quantity'];

    public function supply()
    {
        return $this->belongsTo(Supply::class);
    }
}