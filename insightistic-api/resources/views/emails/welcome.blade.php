<div style="font-family:Arial,sans-serif;color:#1f2733;max-width:560px;margin:0 auto;">
    <div style="border-top:4px solid #2563EB;padding:18px 4px;">
        <h2 style="margin:0 0 10px;color:#2563EB;">Welcome, {{ $user->name }} 👋</h2>
        <p>Your organization <strong>{{ $org->name }}</strong> is ready on Insightistic.</p>
        <p>Next steps to see your business clearly:</p>
        <ol style="padding-left:18px;">
            <li>Add your first website in the dashboard.</li>
            <li>Install the Insightistic Connector plugin and paste your token.</li>
            <li>Run the first sync — your dashboard fills with revenue, customers and AI insights.</li>
        </ol>
        <p style="margin:18px 0;">
            <a href="{{ $appUrl }}/dashboard" style="background:#2563EB;color:#fff;padding:11px 18px;border-radius:8px;text-decoration:none;">Open your dashboard</a>
        </p>
        <p style="color:#9aa3b2;font-size:12px;">You're on a 14-day trial. No card required to explore.</p>
    </div>
</div>
