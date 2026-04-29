<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToLaboratory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class SupplyPack extends Model
{
    use BelongsToLaboratory, HasUuids;
    protected $fillable = ['laboratory_id', 'name', 'is_active'];

    public function items()
    {
        return $this->hasMany(SupplyPackItem::class);
    }
}