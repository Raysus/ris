<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class AppointmentStudy extends Model
{

    use HasUuids;
    protected $fillable = [
        'appointment_id',
        'machine_id',
        'exam_id',
        'sub_exam_id',
        'radiologist_user_id',
        'exam_name',
        'sub_exam_name',
        'fonasa_code',
        'quantity',
        'price',
        'status',
    ];
    public function appointment()
    {
        return $this->belongsTo(Appointment::class);
    }
    public function machine()
    {
        return $this->belongsTo(Machine::class);
    }

    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }

    public function report()
    {
        return $this->hasOne(MedicalReport::class);
    }

    /** Texto en columna appointment_studies.report (evita colisión con relación report()). */
    public function getStoredReportText(): string
    {
        return (string) ($this->attributes['report'] ?? '');
    }

    public function radiologist()
    {
        return $this->belongsTo(User::class, 'radiologist_user_id');
    }
}