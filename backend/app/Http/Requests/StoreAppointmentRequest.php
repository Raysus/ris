<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Carbon\Carbon;

class StoreAppointmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'patient_id' => 'required|uuid|exists:patients,id',
            'machine_id' => 'required|uuid|exists:machines,id',
            'start_time' => 'required|date_format:Y-m-d H:i|after:now',
            'end_time' => 'required|date_format:Y-m-d H:i|after:start_time',
            'insurance_id' => 'nullable|uuid|exists:insurances,id',
            'insurance_plan_id' => 'nullable|uuid|exists:insurance_plans,id',
            'referring_doctor_id' => 'nullable|uuid|exists:referring_doctors,id',
            'destination_doctor_id' => 'nullable|uuid|exists:users,id',
            'priority' => 'nullable|string|in:Normal,Alta,Urgente',
            'origin' => 'nullable|string|max:100',
            'payment_method' => 'nullable|string|max:100',
            'tipo_bono' => 'nullable|string|max:100'
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     */
    public function messages(): array
    {
        return [
            'patient_id.required' => 'El paciente es requerido',
            'patient_id.exists' => 'El paciente no existe',
            'machine_id.required' => 'La máquina es requerida',
            'machine_id.exists' => 'La máquina no existe',
            'start_time.required' => 'La hora de inicio es requerida',
            'start_time.after' => 'La hora de inicio debe ser en el futuro',
            'end_time.after' => 'La hora de fin debe ser posterior a la hora de inicio',
            'priority.in' => 'La prioridad debe ser: Normal, Alta o Urgente'
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Si los datos vienen en JSON (FormData)
        if ($this->has('data')) {
            $data = json_decode($this->input('data'), true);
            $this->merge($data);
        }
    }
}
