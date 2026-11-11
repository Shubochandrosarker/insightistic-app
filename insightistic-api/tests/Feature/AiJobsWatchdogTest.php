<?php

use App\Models\AiInsight;
use App\Models\AiVisibilityPrompt;
use App\Models\Site;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/**
 * Zero-rows watchdog + the ai-visibility scheduled command (Nov 2026 silent
 * AI jobs incident). Rows written is the health signal, not exit codes.
 */
function watchdogSite(string $email, array $siteAttrs = []): array
{
    [, $org] = makeOrgUser($email);
    $site = Site::withoutGlobalScope('organization')->create(array_merge([
        'organization_id'   => $org->id,
        'name'              => 'Watchdog site',
        'domain'            => 'wd-' . uniqid() . '.example.com',
        'connection_status' => 'connected',
    ], $siteAttrs));

    return [$org, $site];
}

it('alerts when eligible sites exist but no ai_insights rows were written', function () {
    Mail::fake();
    config(['insightistic.ops.alert_email' => 'ops@example.com']);
    watchdogSite('wd-zero@example.com');
    Log::spy();

    $this->artisan('insightistic:ai-jobs-watchdog')->assertFailed();

    Log::shouldHaveReceived('critical')
        ->withArgs(fn ($msg, $ctx = []) => str_contains($msg, 'insights-generate wrote 0 ai_insights rows'))
        ->once();
});

it('is quiet when rows were written in the window', function () {
    [$org, $site] = watchdogSite('wd-ok@example.com');
    AiInsight::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'site_id'         => $site->id,
        'type'            => 'traffic',
        'title'           => 'Sessions moved',
        'summary'         => 's',
    ]);

    $this->artisan('insightistic:ai-jobs-watchdog')->assertSuccessful();
});

it('does not alert for sites whose AI work is paused', function () {
    [$org] = watchdogSite('wd-paused@example.com');
    $org->forceFill(['ai_settings_json' => ['paused' => true]])->save();

    $this->artisan('insightistic:ai-jobs-watchdog')->assertSuccessful();
});

it('ai-visibility dispatches for unpaused sites and logs paused skips (closure regression)', function () {
    Queue::fake();
    [, $live] = watchdogSite('aiv-cmd-live@example.com');
    [$pausedOrg, $paused] = watchdogSite('aiv-cmd-paused@example.com');
    $pausedOrg->forceFill(['ai_settings_json' => ['paused' => true]])->save();
    foreach ([$live, $paused] as $s) {
        AiVisibilityPrompt::create(['site_id' => $s->id, 'prompt' => 'best crm', 'active' => true]);
    }
    Log::spy();

    $this->artisan('insightistic:ai-visibility')->assertSuccessful();

    Queue::assertPushed(\App\Jobs\RunAiVisibilityJob::class, 1);
    Queue::assertPushed(\App\Jobs\RunAiVisibilityJob::class, fn ($job) => $job->siteId === $live->id);
    Log::shouldHaveReceived('info')
        ->withArgs(fn ($msg, $ctx = []) => $msg === 'ai_job.ai-visibility.skip'
            && ($ctx['site_id'] ?? null) === $paused->id && ($ctx['reason'] ?? null) === 'ai_paused')
        ->once();
});
