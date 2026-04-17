<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class ReferringDoctor extends Model
{
    protected $fillable = ['rut', 'names', 'last_name_1', 'last_name_2', 'phone', 'email'];
    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }
}