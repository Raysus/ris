<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Instrucciones de examen</title>
</head>
<body style="font-family: Arial, sans-serif; color: #222; line-height: 1.5; max-width: 640px; margin: 0 auto; padding: 24px;">
    <h2 style="color: #0d6efd; margin-bottom: 8px;">Instrucciones para su examen</h2>
    <p>Hola <strong>{{ $nombrePaciente }}</strong>,</p>
    <p>Su cita en <strong>{{ $centro }}</strong> quedó agendada para el <strong>{{ $fechaCita }}</strong>.</p>

    @if($direccion)
        <p><strong>Dirección:</strong> {{ $direccion }}</p>
    @endif
    @if($telefono)
        <p><strong>Teléfono:</strong> {{ $telefono }}</p>
    @endif

    <h3 style="margin-top: 24px;">Exámenes programados</h3>
    <ul>
        @foreach($estudios as $study)
            <li>{{ $study->exam_name }}@if($study->sub_exam_name) — {{ $study->sub_exam_name }}@endif</li>
        @endforeach
    </ul>

    <h3 style="margin-top: 24px;">Indicaciones importantes</h3>
    @foreach($instructions as $instruction)
        <div style="margin-bottom: 20px; padding: 16px; background: #f8f9fa; border-left: 4px solid #0d6efd; border-radius: 4px;">
            <h4 style="margin: 0 0 8px 0;">{{ $instruction->exam?->name ?? 'Examen' }}</h4>
            <div>{!! nl2br(e($instruction->body)) !!}</div>
        </div>
    @endforeach

    <p style="margin-top: 32px; font-size: 14px; color: #666;">
        Si tiene dudas, contáctenos antes de asistir. Este correo fue generado automáticamente por el sistema de agendamiento.
    </p>
</body>
</html>
