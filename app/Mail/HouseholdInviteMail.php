<?php

namespace App\Mail;

use App\Models\HouseholdInvite;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class HouseholdInviteMail extends Mailable
{
    public function __construct(public HouseholdInvite $invite) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->invite->inviter->name} invited you to {$this->invite->household->name} on Snuggy Parking",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.household-invite',
            with: [
                'inviterName' => self::literal($this->invite->inviter->name),
                'householdName' => self::literal($this->invite->household->name),
                'url' => route('invites.show', $this->invite->token),
                'expiresAt' => $this->invite->expires_at->toFormattedDayDateString(),
            ],
        );
    }

    /**
     * Names are user-editable and land in a Markdown body: escape Markdown so a name
     * like "[x](https://…)" stays text instead of becoming a link in the email.
     */
    private static function literal(string $text): string
    {
        return (string) preg_replace('/([\\\\`*_{}\[\]()#+\-.!<>|~])/', '\\\\$1', $text);
    }
}
