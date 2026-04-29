<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class ReferringDoctor extends Model
{
    use HasUuids;
    protected $fillable = ['rut', 'names', 'last_name_1', 'last_name_2', 'phone', 'email'];
    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }
}