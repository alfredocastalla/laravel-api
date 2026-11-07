<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ProgramScheduleNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $residentName,
        public string $programName,
        public string $benefit,
        public string $date,
        public string $time,
        public string $venue,
        public string $releaseAmount,
        public string $assignedStaff,
        public string $instructions,
        public string $status,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Upcoming benefit release: '.$this->programName,
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.program-schedule',
        );
    }
}
