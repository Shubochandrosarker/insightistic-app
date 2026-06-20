<div style="font-family:Arial,sans-serif;color:#1f2733;max-width:560px;margin:0 auto;">
    <div style="border-top:4px solid #2563EB;padding:18px 4px;">
        <h2 style="margin:0 0 10px;color:#2563EB;">You're invited to {{ $org->name }}</h2>
        <p>You've been added to <strong>{{ $org->name }}</strong> on Insightistic as <strong>{{ $role }}</strong>.</p>
        <p>Set your password to get started:</p>
        <p style="margin:18px 0;">
            <a href="{{ $acceptUrl }}" style="background:#2563EB;color:#fff;padding:11px 18px;border-radius:8px;text-decoration:none;">Accept invite &amp; set password</a>
        </p>
        <p style="color:#9aa3b2;font-size:12px;">This link expires in 60 minutes. If you didn't expect this, you can ignore it.</p>
    </div>
</div>
