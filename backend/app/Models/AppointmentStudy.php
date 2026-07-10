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
        'anamnesis',
        'report',
        'dictation_method',
        'audio_path',
        'report_document_path',
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

    public function subExam()
    {
        return $this->belongsTo(SubExam::class, 'sub_exam_id');
    }

    public function report()
    {
        return $this->hasOne(MedicalReport::class);
    }

    /** Texto en columna appointment_studies.report (evita colisión con relación report()). */
    public function getStoredReportText(): string
    {
        $columnText = trim((string) ($this->attributes['report'] ?? ''));
        if ($columnText !== '') {
            return $columnText;
        }

        $medicalReport = $this->relationLoaded('report')
            ? $this->getRelation('report')
            : $this->report()->first();

        return trim((string) ($medicalReport->report_text ?? ''));
    }

    public function radiologist()
    {
        return $this->belongsTo(User::class, 'radiologist_user_id');
    }

    /** Alias de lectura: la columna en BD es `price`. */
    public function getPriceChargedAttribute(): float
    {
        return (float) ($this->attributes['price'] ?? 0);
    }
}