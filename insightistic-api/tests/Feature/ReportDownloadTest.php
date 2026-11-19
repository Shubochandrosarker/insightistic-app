<?php

use App\Models\ClientReport;
use App\Models\Site;
use Illuminate\Support\Facades\Storage;

it('opens proxy-safe report links and downloads HTML only when signed for attachment', function () {
    [, $org] = makeOrgUser('report-download@example.com');
    $site = Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id, 'name' => 'Report demo', 'domain' => 'example.com',
    ]);
    Storage::fake('public');
    Storage::disk('public')->put('report.html', '<h1>Report</h1>');
    Storage::disk('public')->put('report.pdf', '%PDF');
    config(['app.url' => 'https://app.example.com:80', 'insightistic.reports.disk' => 'public']);

    $report = ClientReport::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id, 'site_id' => $site->id, 'title' => 'Report',
        'report_type' => 'weekly', 'period_start' => now()->subWeek(), 'period_end' => now(),
        'html_url' => 'report.html', 'pdf_url' => 'report.pdf',
    ]);

    expect($report->html_link)->toStartWith('https://app.example.com/api/');
    $this->get($report->pdf_link)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get($report->html_link)->assertOk()->assertHeader('Content-Type', 'text/html; charset=UTF-8');
    $this->get($report->html_download_link)->assertOk()->assertDownload("insightistic-report-{$report->id}.html");
    $this->get($report->html_link.'&download=1')->assertForbidden();
});
