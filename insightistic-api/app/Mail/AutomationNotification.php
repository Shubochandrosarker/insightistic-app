<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The `send_notification_email` action's mailable (spec: P6 Automation
 * Studio / P9 Bridgistic Approved Actions — App\Services\Actions\ActionExecutor).
 * A plain-text ad-hoc notification, unlike LifecycleMail's shared premium
 * template — this is a one-off message an automation rule or an approved
 * action proposal composed, not a designed lifecycle touchpoint.
 */
class AutomationNotification extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public string $subjectLine, public string $bodyText) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(text: 'emails.automation-notification', with: ['body' => $this->bodyText]);
    }
}
