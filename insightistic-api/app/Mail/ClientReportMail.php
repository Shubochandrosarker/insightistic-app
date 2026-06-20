<?php

namespace App\Mail;

use App\Models\ClientReport;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ClientReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ClientReport $report,
        public array $brand,
    ) {}

    public function envelope(): Envelope
    {
        $from = $this->brand['email_from_name'] ?? $this->brand['name'] ?? config('app.name');

        return new Envelope(
            subject: $this->report->title,
            using: [], // from address is the configured MAIL_FROM; white-label sender is Week 5
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.report', with: [
            'report' => $this->report,
            'brand'  => $this->brand,
        ]);
    }

    public function attachments(): array
    {
        $disk = config('insightistic.reports.disk', 'public');

        return [
            Attachment::fromStorageDisk($disk, $this->report->pdf_url)
                ->as($this->report->report_type . '-report.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
