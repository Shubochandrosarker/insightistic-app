<?php

namespace App\Mail;

use App\Models\EmailLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * One mailable for the whole lifecycle suite. Copy lives in
 * LifecycleMailer::copyFor(); this class just renders the shared premium
 * template with (heading, lines, optional panel/steps/cta/footnote).
 */
class LifecycleMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public array $body, // heading, lines[], preview?, panel?, steps?, cta?, footnote?
    ) {
    }

    /** email_logs row this send reports into: queued → sent | failed. */
    public ?int $emailLogId = null;

    public function trackedBy(EmailLog $log): static
    {
        $this->emailLogId = $log->id;

        return $this;
    }

    /**
     * Runs inside the queued job (or inline for sync). Only a send that
     * returns marks the row 'sent'; an exception propagates so the job
     * retries, and failed() records the final failure.
     */
    public function send($mailer)
    {
        $result = parent::send($mailer);
        EmailLog::markSent($this->emailLogId);

        return $result;
    }

    /** Called by SendQueuedMailable once retries are exhausted. */
    public function failed(\Throwable $e): void
    {
        EmailLog::markFailed($this->emailLogId, $e);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.lifecycle', with: $this->body + [
            'appUrl' => rtrim(config('insightistic.app_url'), '/'),
        ]);
    }
}
