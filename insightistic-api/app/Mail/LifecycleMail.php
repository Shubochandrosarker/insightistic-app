<?php

namespace App\Mail;

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
