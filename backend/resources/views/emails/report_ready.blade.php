<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Resultados disponibles</title>
</head>
<body style="font-family: Arial, sans-serif; color: #333; line-height: 1.5;">
@php
    $p = $appointment->patient?->persona;
@endphp
<h2 style="color: #2ec4b6;">Resultados disponibles</h2>
<p>Hola {{ trim(($p->names ?? '') . ' ' . ($p->last_name_1 ?? '')) }},</p>
<p>Sus resultados de imagenología en <strong>{{ $appointment->laboratory?->name ?? 'nuestro centro' }}</strong> ya están listos.</p>
@if($portalUrl)
<p>Puede consultar sus resultados en el portal del centro:<br>
    <a href="{{ $portalUrl }}">{{ $portalUrl }}</a>
</p>
@else
<p>Acérquese a recepción con su documento de identidad para retirarlos, o use el portal web del centro si ya tiene acceso.</p>
@endif
<p style="font-size: 12px; color: #666;">Este mensaje es informativo. No responda a este correo.</p>
</body>
</html>
