@php $primary = $brand['primary_color'] ?? '#2563EB'; @endphp
<div style="font-family:Arial,sans-serif;color:#1f2733;max-width:560px;margin:0 auto;">
    <div style="border-top:4px solid {{ $primary }};padding:18px 4px;">
        <h2 style="margin:0 0 8px;color:{{ $primary }};">{{ $brand['name'] ?? 'Insightistic' }}</h2>
        <p style="margin:0 0 14px;">Your {{ $report->report_type }} business report is ready.</p>
        <p style="margin:0 0 6px;color:#6b7280;">
            Period: {{ optional($report->period_start)->toDateString() }} → {{ optional($report->period_end)->toDateString() }}
        </p>
        <p style="margin:14px 0;">The full report is attached as a PDF.</p>
        @if (!empty($brand['footer_text']))
            <p style="margin-top:24px;color:#9aa3b2;font-size:12px;">{{ $brand['footer_text'] }}</p>
        @endif
    </div>
</div>
