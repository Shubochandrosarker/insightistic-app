@php
    $primary = $brand['primary_color'] ?? '#2563EB';
    $accent  = $brand['accent_color'] ?? '#10B981';
    $cur     = $currency ?: 'USD';
    $money   = fn ($n) => $cur . ' ' . number_format((float) $n, 2);
    $delta   = function ($v) {
        if ($v === null) return '<span style="color:#9aa3b2">—</span>';
        $c = $v > 0 ? '#16a34a' : ($v < 0 ? '#dc2626' : '#9aa3b2');
        $a = $v > 0 ? '▲' : ($v < 0 ? '▼' : '·');
        return '<span style="color:' . $c . '">' . $a . ' ' . abs($v) . '%</span>';
    };
    $maxRev = 0;
    foreach ($series as $r) { $maxRev = max($maxRev, (float) $r->revenue); }
    $sev = $ai['severity'] ?? 'low';
    $sevColor = $sev === 'high' ? '#dc2626' : ($sev === 'medium' ? '#d97706' : '#16a34a');
@endphp
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    * { font-family: DejaVu Sans, sans-serif; }
    body { color: #1f2733; font-size: 12px; margin: 0; }
    .wrap { padding: 28px 32px; }
    .header { border-bottom: 3px solid {{ $primary }}; padding-bottom: 14px; margin-bottom: 18px; }
    .header .brand { font-size: 18px; font-weight: bold; color: {{ $primary }}; }
    .header .title { font-size: 15px; margin-top: 4px; }
    .header .period { color: #6b7280; font-size: 11px; margin-top: 2px; }
    .logo { max-height: 40px; }
    h2 { font-size: 12px; text-transform: uppercase; letter-spacing: .5px; color: #6b7280;
         border-bottom: 1px solid #e5e7eb; padding-bottom: 5px; margin: 22px 0 10px; }
    table { width: 100%; border-collapse: collapse; }
    .kpi td { width: 25%; padding: 6px; vertical-align: top; }
    .kpi .box { background: #f7f8fa; border: 1px solid #eceef2; border-radius: 8px; padding: 10px; }
    .kpi .label { color: #6b7280; font-size: 10px; text-transform: uppercase; }
    .kpi .val { font-size: 17px; font-weight: bold; margin-top: 3px; }
    .kpi .d { font-size: 10px; margin-top: 2px; }
    .data th { text-align: left; color: #6b7280; font-weight: normal; font-size: 10px;
               text-transform: uppercase; border-bottom: 1px solid #e5e7eb; padding: 6px 4px; }
    .data td { padding: 6px 4px; border-bottom: 1px solid #f0f1f4; }
    .num { text-align: right; }
    .barrow td { padding: 3px 4px; }
    .bar { background: {{ $primary }}; height: 9px; border-radius: 3px; }
    .ai { border: 1px solid #e5e7eb; border-left: 4px solid {{ $sevColor }}; border-radius: 8px;
          padding: 12px 14px; background: #fbfbfc; }
    .ai .t { font-weight: bold; font-size: 13px; }
    .ai .rec { margin-top: 8px; padding-top: 8px; border-top: 1px dashed #e5e7eb; }
    .footer { margin-top: 26px; padding-top: 10px; border-top: 1px solid #e5e7eb;
              color: #9aa3b2; font-size: 10px; text-align: center; }
</style>
</head>
<body>
<div class="wrap">

    <div class="header">
        @if (!empty($brand['logo_url']))
            <img src="{{ $brand['logo_url'] }}" class="logo" alt="logo">
        @endif
        <div class="brand">{{ $brand['name'] }}</div>
        <div class="title">{{ $report['title'] }}</div>
        <div class="period">{{ $period['from'] }} → {{ $period['to'] }}</div>
    </div>

    {{-- Executive summary (AI) --}}
    <h2>Executive Summary</h2>
    @if ($ai)
        <div class="ai">
            <div class="t">{{ $ai['title'] }}</div>
            <div>{{ $ai['summary'] }}</div>
            @if (!empty($ai['possible_reason']))
                <div style="color:#6b7280;margin-top:6px;">Why: {{ $ai['possible_reason'] }}</div>
            @endif
            <div class="rec"><strong>Recommended action:</strong> {{ $ai['recommendation'] }}</div>
        </div>
    @else
        <div class="ai"><div>Summary generation was unavailable for this report. The metrics below are accurate.</div></div>
    @endif

    {{-- KPIs --}}
    <h2>Key Metrics</h2>
    <table class="kpi">
        <tr>
            <td><div class="box"><div class="label">Revenue</div><div class="val">{{ $money($metrics['revenue']) }}</div><div class="d">{!! $delta($deltas['revenue'] ?? null) !!}</div></div></td>
            <td><div class="box"><div class="label">Orders</div><div class="val">{{ $metrics['orders'] }}</div><div class="d">{!! $delta($deltas['orders'] ?? null) !!}</div></div></td>
            <td><div class="box"><div class="label">Avg Order Value</div><div class="val">{{ $money($metrics['average_order_value']) }}</div><div class="d">{!! $delta($deltas['average_order_value'] ?? null) !!}</div></div></td>
            <td><div class="box"><div class="label">Refunds</div><div class="val">{{ $metrics['refunds'] }}</div><div class="d">{!! $delta($deltas['refunds'] ?? null) !!}</div></div></td>
        </tr>
        <tr>
            <td><div class="box"><div class="label">New Customers</div><div class="val">{{ $metrics['new_customers'] }}</div></div></td>
            <td><div class="box"><div class="label">Returning</div><div class="val">{{ $metrics['returning_customers'] }}</div></div></td>
            <td><div class="box"><div class="label">Products Sold</div><div class="val">{{ $metrics['products_sold'] }}</div></div></td>
            <td><div class="box"><div class="label">Failed Orders</div><div class="val">{{ $metrics['failed_orders'] }}</div></div></td>
        </tr>
    </table>

    {{-- Revenue trend (CSS bars, dompdf-safe) --}}
    <h2>Revenue Trend</h2>
    <table class="barrow">
        @forelse ($series as $row)
            @php $w = $maxRev > 0 ? max(1, round(((float) $row->revenue / $maxRev) * 100)) : 0; @endphp
            <tr>
                <td style="width:90px;color:#6b7280;">{{ $row->date->format('M d') }}</td>
                <td><div class="bar" style="width: {{ $w }}%;"></div></td>
                <td style="width:90px;" class="num">{{ $money($row->revenue) }}</td>
            </tr>
        @empty
            <tr><td style="color:#9aa3b2;">No daily data in this period.</td></tr>
        @endforelse
    </table>

    {{-- Top products --}}
    <h2>Top Products</h2>
    <table class="data">
        <tr><th>Product</th><th class="num">Units</th><th class="num">Revenue</th></tr>
        @forelse ($topProducts as $p)
            <tr><td>{{ $p['name'] ?: '—' }}</td><td class="num">{{ $p['units'] }}</td><td class="num">{{ $money($p['revenue']) }}</td></tr>
        @empty
            <tr><td colspan="3" style="color:#9aa3b2;">No product sales in this period.</td></tr>
        @endforelse
    </table>

    {{-- Top customers --}}
    <h2>Top Customers</h2>
    <table class="data">
        <tr><th>Customer</th><th class="num">Orders</th><th class="num">Spent</th></tr>
        @forelse ($topCustomers as $c)
            <tr><td>{{ $c['name'] }}</td><td class="num">{{ $c['orders'] }}</td><td class="num">{{ $money($c['spent']) }}</td></tr>
        @empty
            <tr><td colspan="3" style="color:#9aa3b2;">No customers yet.</td></tr>
        @endforelse
    </table>

    <div class="footer">{{ $brand['footer_text'] }}</div>
</div>
</body>
</html>
