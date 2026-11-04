<?php

use App\Mail\LifecycleMail;
use App\Models\EmailLog;
use App\Services\LifecycleMailer;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * email_logs must record what really happened to a lifecycle email:
 * queued at dispatch, sent only after the mailer returns, failed (with a
 * redacted error) when delivery throws, skipped for reserved domains.
 */

function elsOrg(string $local = 'els'): \App\Models\Organization
{
    [, $org] = makeOrgUser($local.'-'.uniqid().'@insightistic.com');

    return $org;
}

function elsLog(\App\Models\Organization $org, string $type = 'trial_expired'): EmailLog
{
    return EmailLog::where('organization_id', $org->id)->where('email_type', $type)->firstOrFail();
}

function elsFailingMailer(): void
{
    Mail::extend('els-boom', fn () => new class extends AbstractTransport {
        protected function doSend(SentMessage $message): void
        {
            throw new TransportException('554 5.7.1 <owner@client.com>: relay denied; password=hunter2 Bearer abc.def.ghi');
        }

        public function __toString(): string
        {
            return 'els-boom';
        }
    });
    config(['mail.mailers.els-boom' => ['transport' => 'els-boom'], 'mail.default' => 'els-boom']);
}

it('records queued at dispatch, not sent', function () {
    Mail::fake();
    $org = elsOrg();

    expect(app(LifecycleMailer::class)->send($org, 'trial_expired'))->toBeTrue();

    $log = elsLog($org);
    expect($log->status)->toBe('queued')->and($log->sent_at)->toBeNull()->and($log->error)->toBeNull();
    Mail::assertQueued(LifecycleMail::class, fn (LifecycleMail $m) => $m->emailLogId === $log->id);
});

it('records sent only once the mailer actually delivers', function () {
    config(['mail.default' => 'array']); // queue is sync in tests: the job runs inline
    $org = elsOrg();

    app(LifecycleMailer::class)->send($org, 'trial_expired');

    $log = elsLog($org);
    expect($log->status)->toBe('sent')->and($log->sent_at)->not->toBeNull()->and($log->error)->toBeNull();
});

it('records failed with a truncated, redacted error when delivery throws', function () {
    elsFailingMailer();
    $org = elsOrg();

    expect(app(LifecycleMailer::class)->send($org, 'trial_expired'))->toBeFalse();

    $log = elsLog($org);
    expect($log->status)->toBe('failed')
        ->and($log->sent_at)->toBeNull()
        ->and($log->error)->toContain('554 5.7.1')
        ->and($log->error)->toContain('***@client.com')
        ->and($log->error)->not->toContain('hunter2')
        ->and($log->error)->not->toContain('owner@')
        ->and($log->error)->not->toContain('abc.def.ghi')
        ->and(mb_strlen($log->error))->toBeLessThanOrEqual(500);
});

it('marks the row failed from the queued job failed() handler after retries', function () {
    Mail::fake();
    $org = elsOrg();
    app(LifecycleMailer::class)->send($org, 'trial_expired');
    $log = elsLog($org);

    $mailable = (new LifecycleMail('s', ['heading' => 'h', 'lines' => []]))->trackedBy($log);
    (new SendQueuedMailable($mailable))->failed(new RuntimeException(str_repeat('x', 2000)));

    $log->refresh();
    expect($log->status)->toBe('failed')->and(mb_strlen($log->error))->toBeLessThanOrEqual(500);
});

it('never downgrades a sent row and clears the error when a retry succeeds', function () {
    elsFailingMailer();
    $org = elsOrg();
    app(LifecycleMailer::class)->send($org, 'trial_expired');
    expect(elsLog($org)->status)->toBe('failed');

    // Retry (PR #138 updateOrCreate path) on a working mailer reuses the row.
    config(['mail.default' => 'array']);
    expect(app(LifecycleMailer::class)->send($org, 'trial_expired'))->toBeTrue();
    $log = elsLog($org);
    expect($log->status)->toBe('sent')->and($log->error)->toBeNull()
        ->and(EmailLog::where('organization_id', $org->id)->count())->toBe(1);

    EmailLog::markFailed($log->id, new RuntimeException('late'));
    expect($log->fresh()->status)->toBe('sent');
});

it('still records skipped for reserved domains without queueing', function () {
    config(['insightistic.skip_reserved_mail_domains' => true]);
    Mail::fake();
    [, $org] = makeOrgUser('els-'.uniqid().'@example.com');

    expect(app(LifecycleMailer::class)->send($org, 'trial_expired'))->toBeFalse();

    Mail::assertNothingQueued();
    $log = elsLog($org);
    expect($log->status)->toBe('skipped')->and($log->error)->toBeNull();
});
