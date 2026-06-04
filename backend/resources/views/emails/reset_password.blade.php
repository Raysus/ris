<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Restablecer contraseña</title>
</head>
<body style="font-family: Arial, sans-serif; color: #101860; line-height: 1.5; background: #f4f2f8; margin: 0; padding: 24px;">
    <div style="max-width: 520px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 28px; border: 1px solid #d8d4e4;">
        <h2 style="color: #7d2181; margin-top: 0;">Restablecer contraseña</h2>
        @if($recipientName !== '')
            <p>Hola {{ $recipientName }},</p>
        @else
            <p>Hola,</p>
        @endif
        <p>Recibimos una solicitud para restablecer la contraseña de su cuenta en <strong>HealthTICloud RIS</strong>.</p>
        <p style="text-align: center; margin: 28px 0;">
            <a href="{{ $recoveryLink }}"
               style="display: inline-block; background: #7d2181; color: #fff; text-decoration: none; padding: 12px 24px; border-radius: 8px; font-weight: bold;">
                Crear nueva contraseña
            </a>
        </p>
        <p style="font-size: 13px; color: #4a5568;">Si el botón no funciona, copie y pegue este enlace en su navegador:</p>
        <p style="font-size: 12px; word-break: break-all; color: #4a5568;">{{ $recoveryLink }}</p>
        <p style="font-size: 12px; color: #666; margin-top: 24px;">Si no solicitó este cambio, ignore este correo. El enlace expira en 60 minutos.</p>
    </div>
</body>
</html>
