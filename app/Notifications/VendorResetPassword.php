<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The vendor-guard counterpart of Laravel's built-in reset mail. It exists so
 * the link points at the portal's own reset screen rather than the staff one.
 */
class VendorResetPassword extends Notification
{
    public function __construct(
        #[\SensitiveParameter] private string $token,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('vendor.password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        $minutes = config('auth.passwords.vendors.expire', 60);

        return (new MailMessage)
            ->subject('Reset your '.config('app.name').' password')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('We received a request to reset your vendor portal password.')
            ->action('Reset password', $url)
            ->line("This link expires in {$minutes} minutes.")
            ->line('If you did not request a reset, no action is needed — your password stays as it is.')
            ->salutation('— '.config('app.name'));
    }
}
