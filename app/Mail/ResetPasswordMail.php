<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ResetPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $resetUrl;

    public function __construct(public User $user, string $token)
    {
        $frontendUrl    = config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173'));
        $this->resetUrl = $frontendUrl . '/reset-password?token=' . $token . '&email=' . urlencode($user->email);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset Password — ' . config('app.name'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.reset-password');
    }
}
