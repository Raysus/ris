<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToLaboratory;

class SupplyPack extends Model
{
    use BelongsToLaboratory;
    protected $fillable = ['laboratory_id', 'name', 'is_active'];

    public function items()
    {
        return $this->hasMany(SupplyPackItem::class);
    }
}