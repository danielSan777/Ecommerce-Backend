<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ $titulo }}</title></head>
<body style="margin:0;background:#f0fdf4;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f0fdf4;padding:24px 12px;">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden;">
<tr><td style="background:#047857;color:#ffffff;padding:18px 24px;font-size:20px;font-weight:bold;">🌿 Jardín de Sele</td></tr>
<tr><td style="padding:24px;">
<h1 style="margin:0 0 12px;font-size:20px;color:#065f46;">{{ $titulo }}</h1>
<p style="margin:0 0 20px;line-height:1.5;">{{ $texto }}</p>
<p style="margin:20px 0;text-align:center;"><a href="{{ $enlace }}" style="background:#047857;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:8px;font-weight:bold;display:inline-block;">{{ $boton }}</a></p>
<p style="margin:0 0 8px;font-size:13px;color:#6b7280;line-height:1.5;">Si el botón no funciona, copia este enlace en tu navegador:<br><span style="word-break:break-all;">{{ $enlace }}</span></p>
<p style="margin:12px 0 0;font-size:13px;color:#6b7280;line-height:1.5;">{{ $aviso }}</p>
</td></tr>
</table>
</td></tr></table>
</body>
</html>
