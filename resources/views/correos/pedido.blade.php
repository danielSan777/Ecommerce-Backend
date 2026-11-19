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
<p style="margin:0 0 16px;line-height:1.5;">{{ $intro }}</p>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ecfdf5;border-radius:8px;margin:0 0 16px;">
<tr><td style="padding:12px 16px;line-height:1.6;">
<strong>Pedido {{ $numero }}</strong><br>
@foreach ($productos as $nombre)• {{ $nombre }}<br>@endforeach
Total: <strong>${{ $total }}</strong>
@isset($ventana)<br>Entrega estimada: <strong>{{ $ventana }}</strong>@endisset
</td></tr></table>

@isset($limite)<p style="margin:0 0 12px;line-height:1.5;"><strong>{{ $limite }}</strong></p>@endisset

@isset($cuentas)
<p style="margin:0 0 6px;"><strong>Cuentas para transferir</strong></p>
@foreach ($cuentas as $c)
<p style="margin:0 0 8px;line-height:1.5;font-size:14px;">
{{ $c['bank'] }} · {{ $c['type'] }}<br>N.º {{ $c['number'] }}<br>{{ $c['holder'] }} · C.I. {{ $c['id_number'] }}</p>
@endforeach
@endisset

@isset($guia)
<p style="margin:0 0 12px;line-height:1.5;">Guía de Servientrega: <strong>{{ $guia }}</strong>
@isset($enlaceGuia)<br><a href="{{ $enlaceGuia }}" style="color:#047857;">Ver mi guía en Servientrega</a>@endisset</p>
@endisset

@isset($motivo)<p style="margin:0 0 12px;line-height:1.5;"><strong>Motivo:</strong> {{ $motivo }}</p>@endisset
@isset($detalle)<p style="margin:0 0 16px;line-height:1.5;">{{ $detalle }}</p>@endisset

<p style="margin:20px 0;text-align:center;">
<a href="{{ $enlace }}" style="display:inline-block;background:#047857;color:#ffffff;text-decoration:none;font-weight:bold;padding:14px 24px;border-radius:8px;">{{ $boton }}</a>
</p>
<p style="margin:0;font-size:13px;color:#6b7280;line-height:1.5;">Guarda este correo: con este enlace puedes ver tu pedido desde cualquier celular o computador. Si tienes dudas, responde a este correo.</p>
</td></tr>
</table>
</td></tr></table>
</body>
</html>
