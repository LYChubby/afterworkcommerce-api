<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected string $token
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = rtrim(
            config('services.frontend.url'),
            '/'
        );

        $resetUrl = $frontendUrl . '/reset-password?' . http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        return (new MailMessage)
            ->subject('Reset Password AFTERWORK-COMMERCE')
            ->greeting('Halo!')
            ->line('Kami menerima permintaan untuk mereset password akun Anda.')
            ->action('Reset Password', $resetUrl)
            ->line('Tautan ini berlaku selama 60 menit.')
            ->line('Jika Anda tidak meminta reset password, abaikan email ini.');
    }
}
