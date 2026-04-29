<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Payment extends Model
{
    use SoftDeletes, HasUuids;
    protected $fillable = ['appointment_id', 'user_id', 'amount', 'payment_method', 'transaction_code', 'status'];
    public function appointment()
    {
        return $this->belongsTo(Appointment::class);
    }
    public function cashier()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}