<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f5f7fb;">
<div style="display:none;max-height:0;overflow:hidden;">{{ $preview ?? '' }}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fb;padding:32px 12px;">
    <tr><td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #e6eaf2;">
            <tr><td style="height:4px;background:linear-gradient(90deg,#2563EB,#7C3AED);font-size:0;line-height:0;">&nbsp;</td></tr>
            <tr><td style="padding:28px 32px 8px;font-family:Arial,Helvetica,sans-serif;">
                <span style="font-size:15px;font-weight:bold;color:#2563EB;letter-spacing:.3px;">Insightistic</span>
            </td></tr>
            <tr><td style="padding:8px 32px 4px;font-family:Arial,Helvetica,sans-serif;">
                <h1 style="margin:0 0 12px;font-size:21px;line-height:1.35;color:#101828;">{{ $heading }}</h1>
                @foreach ($lines as $line)
                    <p style="margin:0 0 12px;font-size:14px;line-height:1.65;color:#3d4757;">{!! $line !!}</p>
                @endforeach
                @isset ($panel)
                    <div style="margin:18px 0;padding:16px 18px;background:#f1f5ff;border:1px solid #dbe4ff;border-radius:10px;">
                        <p style="margin:0 0 6px;font-size:11px;letter-spacing:.8px;text-transform:uppercase;color:#5b6b8c;">{{ $panel['label'] }}</p>
                        <p style="margin:0;font-size:15px;font-family:'Courier New',monospace;color:#101828;word-break:break-all;">{{ $panel['value'] }}</p>
                    </div>
                @endisset
                @isset ($steps)
                    <ol style="margin:0 0 14px;padding-left:20px;">
                        @foreach ($steps as $step)
                            <li style="margin:0 0 8px;font-size:14px;line-height:1.6;color:#3d4757;">{!! $step !!}</li>
                        @endforeach
                    </ol>
                @endisset
                @isset ($cta)
                    <p style="margin:22px 0;">
                        <a href="{{ $cta['url'] }}" style="display:inline-block;background:#2563EB;color:#ffffff;padding:12px 22px;border-radius:9px;text-decoration:none;font-size:14px;font-weight:bold;">{{ $cta['label'] }}</a>
                    </p>
                @endisset
                @isset ($footnote)
                    <p style="margin:0 0 8px;font-size:12px;line-height:1.6;color:#9aa3b2;">{!! $footnote !!}</p>
                @endisset
            </td></tr>
            <tr><td style="padding:18px 32px 26px;font-family:Arial,Helvetica,sans-serif;border-top:1px solid #eef1f6;">
                <p style="margin:0;font-size:11px;line-height:1.7;color:#9aa3b2;">
                    Insightistic — analytics, reports and AI insights for WordPress &amp; WooCommerce.<br>
                    You're receiving this because you have an Insightistic account.
                    <a href="{{ $appUrl }}/dashboard/settings" style="color:#2563EB;text-decoration:none;">Email preferences</a>
                </p>
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
