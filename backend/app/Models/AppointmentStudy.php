<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class AppointmentStudy extends Model
{
    protected $fillable = [
        'appointment_id',
        'machine_id',
        'exam_id',
        'sub_exam_id',
        'exam_name',
        'sub_exam_name',
        'fonasa_code',
        'quantity',
        'price',
        'status'
    ];
    public function appointment()
    {
        return $this->belongsTo(Appointment::class);
    }
    public function machine()
    {
        return $this->belongsTo(Machine::class);
    }
    public function report()
    {
        return $this->hasOne(MedicalReport::class);
    }
}