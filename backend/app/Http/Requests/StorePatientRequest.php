<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePatientRequest extends FormRequest
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
            'rut' => 'required|string|max:20',
            'names' => 'required|string|max:255',
            'last_name_1' => 'required|string|max:255',
            'last_name_2' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'gender' => 'nullable|string|in:Masculino,Femenino,Otro',
            'birth_date' => 'nullable|date_format:Y-m-d|before:today',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:255',
            'insurance_id' => 'nullable|uuid|exists:insurances,id'
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     */
    public function messages(): array
    {
        return [
            'rut.required' => 'El RUT es requerido',
            'names.required' => 'Los nombres son requeridos',
            'last_name_1.required' => 'El apellido paterno es requerido',
            'email.email' => 'El email debe ser válido',
            'birth_date.before' => 'La fecha de nacimiento debe ser anterior a hoy',
            'gender.in' => 'El género debe ser: Masculino, Femenino u Otro',
            'insurance_id.exists' => 'La aseguradora no existe'
        ];
    }
}
