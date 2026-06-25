<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

class VerifyEmailMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $verifyUrl;

    public function __construct(public User $user)
    {
        $this->verifyUrl = URL::temporarySignedRoute(
            'email.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Verifikasi Alamat Email Anda — ' . config('app.name'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.verify-email');
    }
}
