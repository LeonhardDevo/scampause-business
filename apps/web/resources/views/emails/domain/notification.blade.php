<!doctype html>
<html lang="{{ $locale }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $subject }}</title>
</head>
<body style="margin:0;padding:0;background:#f8fafc;font-family:'IBM Plex Sans',Arial,sans-serif;color:#0f172a;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;padding:24px 0;">
<tr>
<td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border:1px solid #e2e8f0;border-radius:8px;">
<tr>
<td style="padding:4px 0 0 0;background:#f59e0b;border-radius:8px 8px 0 0;"></td>
</tr>
<tr>
<td style="padding:24px 32px 8px 32px;">
<p style="margin:0 0 4px 0;font-size:12px;letter-spacing:0.08em;text-transform:uppercase;color:#475569;">{{ $sender }}</p>
<h1 style="margin:0;font-size:20px;line-height:28px;color:#0f172a;">{{ $heading }}</h1>
</td>
</tr>
<tr>
<td style="padding:8px 32px 0 32px;font-size:15px;line-height:22px;">
@foreach ($lines as $line)
<p style="margin:0 0 12px 0;">{{ $line }}</p>
@endforeach
@if (count($bullets) > 0)
<ul style="margin:0 0 12px 0;padding-left:20px;">
@foreach ($bullets as $bullet)
<li style="margin:0 0 4px 0;">{{ $bullet }}</li>
@endforeach
</ul>
@endif
</td>
</tr>
@if ($actionUrl !== null)
<tr>
<td style="padding:8px 32px 16px 32px;">
<a href="{{ $actionUrl }}" style="display:inline-block;background:#0369a1;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:6px;font-weight:600;">{{ $actionLabel }}</a>
</td>
</tr>
@endif
<tr>
<td style="padding:16px 32px 24px 32px;border-top:1px solid #e2e8f0;font-size:12px;line-height:18px;color:#475569;">
@foreach ($footer as $footerLine)
<p style="margin:0 0 6px 0;">{{ $footerLine }}</p>
@endforeach
@if ($privacyUrl !== null)
<p style="margin:0;"><a href="{{ $privacyUrl }}" style="color:#0369a1;">{{ $privacyLabel }}</a>@if ($noticeUrl !== null) · <a href="{{ $noticeUrl }}" style="color:#0369a1;">{{ $noticeLabel }}</a>@endif</p>
@endif
</td>
</tr>
</table>
</td>
</tr>
</table>
</body>
</html>
