<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\BelongsToLaboratory;

class Supply extends Model
{
    use SoftDeletes, BelongsToLaboratory;
    protected $fillable = ['laboratory_id', 'category', 'name', 'stock', 'max_stock', 'price', 'is_active'];

    public function appointments()
    {
        return $this->belongsToMany(Appointment::class, 'appointment_supplies')
            ->withPivot('quantity', 'price_charged')
            ->withTimestamps();
    }
}
