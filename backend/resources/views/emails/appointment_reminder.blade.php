<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Recordatorio de cita</title>
</head>
<body style="font-family: Arial, sans-serif; color: #333; line-height: 1.5;">
@php
    $p = $appointment->patient?->persona;
    $fecha = $appointment->start_time?->timezone(\App\Support\LabTimezone::name())->format('d/m/Y');
    $hora = $appointment->start_time?->timezone(\App\Support\LabTimezone::name())->format('H:i');
@endphp
<h2 style="color: #5b4a82;">Recordatorio de cita</h2>
<p>Hola {{ trim(($p->names ?? '') . ' ' . ($p->last_name_1 ?? '')) }},</p>
<p>Le recordamos que <strong>mañana</strong> tiene cita en {{ $appointment->laboratory?->name ?? 'nuestro centro' }}:</p>
<ul>
    <li><strong>Fecha:</strong> {{ $fecha }}</li>
    <li><strong>Hora:</strong> {{ $hora }}</li>
    <li><strong>Sala:</strong> {{ $appointment->machine?->name ?? 'Por asignar' }}</li>
</ul>
@if($portalUrl)
<p>Detalle de su cita: <a href="{{ $portalUrl }}">{{ $portalUrl }}</a></p>
@endif
<p style="font-size: 12px; color: #666;">Llegue con su documento de identidad y orden médica si corresponde.</p>
</body>
</html>
