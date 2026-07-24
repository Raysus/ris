<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Nueva solicitud de soporte</title>
</head>
<body style="font-family: Arial, sans-serif; color: #333; line-height: 1.5;">
@php
    $creator = $ticket->creator?->persona;
    $creatorName = $creator
        ? trim(($creator->names ?? '') . ' ' . ($creator->last_name_1 ?? ''))
        : ($ticket->creator?->username ?? 'Usuario');
@endphp
<h2 style="color: #101860;">Nueva solicitud de soporte</h2>
<p>Hay una solicitud nueva en <strong>{{ $ticket->laboratory?->name ?? 'el laboratorio' }}</strong>.</p>
<p>
    <strong>Asunto:</strong> {{ $ticket->subject }}<br>
    <strong>Módulo:</strong> {{ $ticket->module }}<br>
    <strong>Prioridad:</strong> {{ $ticket->priority }}<br>
    <strong>Solicitante:</strong> {{ $creatorName }}
</p>
<p style="white-space: pre-wrap; background: #f5f6fa; padding: 12px; border-radius: 6px;">{{ $ticket->body }}</p>
<p>Revise la cola de <strong>Soporte</strong> en el RIS (pestaña Cola).</p>
<p style="font-size: 12px; color: #666;">Este mensaje es informativo. No responda a este correo.</p>
</body>
</html>
