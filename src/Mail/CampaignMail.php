<?php

declare(strict_types=1);

namespace LaravelMail\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class CampaignMail extends Mailable
{
    public function __construct(
        public string $subjectLine,
        public string $body,
        public ?string $fromAddress = null,
        public ?string $fromName = null,
    ) {
    }

    public function envelope(): Envelope
    {
        $from = $this->fromAddress ?: config('laravelmail.from.address');

        return new Envelope(
            from: $from ? new Address($from, $this->fromName ?: config('laravelmail.from.name')) : null,
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->body);
    }
}
