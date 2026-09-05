<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VendorEmailOtp extends Notification
{
    public function __construct(
        #[\SensitiveParameter] private string $code,
        private int $ttlMinutes,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your '.config('app.name').' verification code: '.$this->code)
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Use this code to finish verifying your vendor account:')
            ->line('**'.$this->code.'**')
            ->line("The code expires in {$this->ttlMinutes} minutes and can only be used once.")
            ->line('If you did not create this account, you can ignore this email.')
            ->salutation('— '.config('app.name'));
    }
}
