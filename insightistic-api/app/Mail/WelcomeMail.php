<?php

namespace App\Mail;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user, public Organization $org) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Welcome to Insightistic');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.welcome', with: [
            'user'   => $this->user,
            'org'    => $this->org,
            'appUrl' => rtrim(config('insightistic.app_url'), '/'),
        ]);
    }
}
