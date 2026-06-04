<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\BelongsToLaboratory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Appointment extends Model
{
    use SoftDeletes, BelongsToLaboratory, HasUuids;

    protected $fillable = [
        'laboratory_id',
        'patient_id',
        'machine_id',
        'start_time',
        'end_time',
        'status',
        'referring_doctor_id',
        'destination_doctor_id',
        'priority',
        'origin',
        'payment_method',
        'payment_status',
        'transaction_code',
        'medical_order_path',
        'survey_path',
        'insurance_id',
        'insurance_plan_id',
        'tipo_bono',
        'entidad_pagadora',
        'accession_number',
        'study_instance_uid',
        'reminder_sent_at',
        'images_received_at',
    ];
    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'needs_review' => 'boolean',
        'reminder_sent_at' => 'datetime',
        'images_received_at' => 'datetime',
    ];

    public function patient()
    {
        return $this->belongsTo(Paciente::class, 'patient_id');
    }
    public function machine()
    {
        return $this->belongsTo(Machine::class);
    }

    public function laboratory()
    {
        return $this->belongsTo(Laboratory::class);
    }

    public function studies()
    {
        return $this->hasMany(AppointmentStudy::class);
    }

    public function supplies()
    {
        return $this->belongsToMany(Supply::class, 'appointment_supplies')
            ->withPivot('quantity', 'price_charged')
            ->withTimestamps();
    }

    public function referringDoctor()
    {
        return $this->belongsTo(ReferringDoctor::class, 'referring_doctor_id');
    }

    public function insurance()
    {
        return $this->belongsTo(Insurance::class);
    }

    public function insurancePlan()
    {
        return $this->belongsTo(InsurancePlan::class);
    }

    public function logs()
    {
        return $this->hasMany(AppointmentLog::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function fonasaBonos()
    {
        return $this->hasMany(FonasaBono::class);
    }

    public function electronicDocuments()
    {
        return $this->hasMany(ElectronicDocument::class);
    }

    public function destinationDoctor()
    {
        return $this->belongsTo(User::class, 'destination_doctor_id');
    }

}