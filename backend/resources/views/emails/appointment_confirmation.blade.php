<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Confirmación de cita</title>
</head>
<body style="font-family: Arial, sans-serif; color: #333; line-height: 1.5;">
@php
    $p = $appointment->patient?->persona;
    $fecha = $appointment->start_time?->timezone(config('app.timezone'))->format('d/m/Y');
    $hora = $appointment->start_time?->timezone(config('app.timezone'))->format('H:i');
@endphp
<h2 style="color: #a894c4;">Cita confirmada</h2>
<p>Hola {{ trim(($p->names ?? '') . ' ' . ($p->last_name_1 ?? '')) }},</p>
<p>Su cita en <strong>{{ $appointment->laboratory?->name ?? 'nuestro centro' }}</strong> quedó registrada:</p>
<ul>
    <li><strong>Fecha:</strong> {{ $fecha }}</li>
    <li><strong>Hora:</strong> {{ $hora }}</li>
    <li><strong>Sala:</strong> {{ $appointment->machine?->name ?? 'Por asignar' }}</li>
    <li><strong>Exámenes:</strong>
        {{ $appointment->studies->pluck('exam_name')->filter()->unique()->implode(', ') ?: 'Según orden médica' }}
    </li>
</ul>
@if($portalUrl)
<p>Puede ingresar al portal de pacientes del centro:<br>
    <a href="{{ $portalUrl }}">{{ $portalUrl }}</a>
</p>
@endif
<p style="font-size: 12px; color: #666;">Si necesita reprogramar, contacte a recepción del centro.</p>
</body>
</html>
