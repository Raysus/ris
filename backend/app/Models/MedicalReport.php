<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class MedicalReport extends Model
{
    use SoftDeletes, HasUuids;
    protected $fillable = ['appointment_study_id', 'radiologist_id', 'transcriptionist_id', 'report_text', 'audio_path', 'status', 'signed_at'];
    protected $casts = ['signed_at' => 'datetime'];

    public function study()
    {
        return $this->belongsTo(AppointmentStudy::class, 'appointment_study_id');
    }
    public function versions()
    {
        return $this->hasMany(ReportVersion::class);
    }
}