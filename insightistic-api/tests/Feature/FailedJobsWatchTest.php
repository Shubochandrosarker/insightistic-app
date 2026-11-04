<?php

use App\Jobs\CrawlUrlJob;
use App\Mail\LifecycleMail;
use App\Models\CrawlChange;
use App\Models\CrawlFrontier;
use App\Models\CrawlPage;
use App\Models\CrawlRun;
use App\Models\EmailLog;
use App\Models\Site;
use App\Services\Crawler\CrawlRunExecutor;
use App\Services\LifecycleMailer;
use App\Support\MailRecipient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/**
 * Regressions behind the System Health "Watch" queue failures (Nov 2026):
 * lifecycle mail to reserved domains (554 5.7.1), crawl finalize aborted
 * by an oversized crawl_changes value (25P02), and crawl hops lost to a
 * worker restart (MaxAttemptsExceededException).
 */

function fjwSite(): Site
{
    [, $org] = makeOrgUser('fjw-'.uniqid().'@insightistic.com');

    return Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id, 'name' => 'fjw.example.org', 'domain' => 'fjw.example.org',
    ]);
}

function fjwRun(Site $site, string $status): CrawlRun
{
    return CrawlRun::query()->create([
        'site_id' => $site->id, 'status' => $status, 'trigger' => 'manual',
        'started_at' => Carbon::now()->subMinutes(5),
        'completed_at' => $status === 'completed' ? Carbon::now()->subMinute() : null,
        'max_pages' => 100,
        'config_json' => ['base_url' => 'https://fjw.example.org', 'max_depth' => 4],
    ]);
}

function fjwPage(CrawlRun $run, string $meta): void
{
    $url = 'https://fjw.example.org/p';
    CrawlPage::query()->create([
        'run_id' => $run->id, 'site_id' => $run->site_id, 'url_hash' => sha1($url), 'url' => $url,
        'status_code' => 200, 'indexable' => true, 'title' => 'T', 'meta_description' => $meta,
        'h1_count' => 1, 'word_count' => 300, 'canonical_url' => $url,
        'content_hash' => sha1('c'), 'created_at' => Carbon::now(),
    ]);
}

it('treats RFC 2606 / 6761 reserved domains as undeliverable', function () {
    config(['insightistic.skip_reserved_mail_domains' => true]);

    foreach (['a@example.com', 'a@mail.example.org', 'a@EXAMPLE.NET', 'a@site.test', 'a@x.invalid', 'a@localhost', 'not-an-email', ''] as $bad) {
        expect(MailRecipient::isDeliverable($bad))->toBeFalse($bad);
    }
    foreach (['a@insightistic.com', 'a@gmail.com', 'a@examples.com', 'a@myexample.com'] as $ok) {
        expect(MailRecipient::isDeliverable($ok))->toBeTrue($ok);
    }
});

it('skips lifecycle mail to a reserved domain, records it once, and never queues it', function () {
    config(['insightistic.skip_reserved_mail_domains' => true]);
    Mail::fake();
    [, $org] = makeOrgUser('e2e-'.uniqid().'@example.com');

    expect(app(LifecycleMailer::class)->send($org, 'trial_expired'))->toBeFalse()
        ->and(app(LifecycleMailer::class)->send($org, 'trial_expired'))->toBeFalse();

    Mail::assertNothingQueued();
    expect(EmailLog::where('organization_id', $org->id)->where('email_type', 'trial_expired')->pluck('status')->all())
        ->toBe(['skipped']);
});

it('still queues lifecycle mail to a real domain, and a failed row can be retried', function () {
    config(['insightistic.skip_reserved_mail_domains' => true]);
    Mail::fake();
    [, $org] = makeOrgUser('owner-'.uniqid().'@insightistic.com');
    EmailLog::create([
        'organization_id' => $org->id, 'user_id' => $org->owner_user_id, 'email_type' => 'trial_expired',
        'period_key' => 'once', 'recipient' => 'x@insightistic.com', 'subject' => 's', 'status' => 'failed',
        'created_at' => now(),
    ]);

    expect(app(LifecycleMailer::class)->send($org, 'trial_expired'))->toBeTrue();
    Mail::assertQueued(LifecycleMail::class, 1);
});

it('clips long change values so finalize completes instead of aborting the transaction', function () {
    $site = fjwSite();
    $one = fjwRun($site, 'completed');
    fjwPage($one, str_repeat('old description ', 60));
    $two = fjwRun($site, 'running');
    fjwPage($two, str_repeat('new description ', 60)); // 960 chars > varchar(500)

    app(CrawlRunExecutor::class)->finalizeRun($two);

    expect($two->fresh()->status)->toBe('completed');
    $change = CrawlChange::query()->where('run_id', $two->id)->where('field', 'meta_description')->first();
    expect($change)->not->toBeNull()
        ->and(mb_strlen($change->new_value))->toBe(500);
});

it('keeps the finalize transaction usable when change detection throws a SQL error', function () {
    $site = fjwSite();
    $run = fjwRun($site, 'running');
    app()->bind(\App\Services\Crawler\ChangeDetection::class, fn () => new class {
        public function detect(...$a): void { DB::statement('select 1/0'); }
    });

    app(CrawlRunExecutor::class)->finalizeRun($run);

    expect($run->fresh()->status)->toBe('completed');
});

it('has a queue retry_after longer than the crawl hop timeout', function () {
    expect(config('queue.connections.database.retry_after'))->toBeGreaterThan((new CrawlUrlJob(1))->timeout);
});

it('restarts a stranded crawl chain when a hop is given up', function () {
    Queue::fake();
    $site = fjwSite();
    $run = fjwRun($site, 'running');
    foreach (['crawling', 'pending'] as $i => $status) {
        CrawlFrontier::query()->create([
            'run_id' => $run->id, 'site_id' => $site->id, 'url_hash' => sha1("u$i"),
            'url' => "https://fjw.example.org/u$i", 'depth' => 1, 'status' => $status, 'created_at' => now(),
        ]);
    }

    (new CrawlUrlJob($run->id))->failed(new RuntimeException('lost'));

    Queue::assertPushed(CrawlUrlJob::class, 1);
    expect(DB::table('crawl_frontier')->where('run_id', $run->id)->where('status', 'crawling')->count())->toBe(0);
});
