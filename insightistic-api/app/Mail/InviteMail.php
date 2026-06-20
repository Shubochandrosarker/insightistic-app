<?php

namespace App\Mail;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public Organization $org,
        public string $acceptUrl,
        public string $role,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "You've been invited to {$this->org->name} on Insightistic");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.invite', with: [
            'user'      => $this->user,
            'org'       => $this->org,
            'acceptUrl' => $this->acceptUrl,
            'role'      => $this->role,
        ]);
    }
}
