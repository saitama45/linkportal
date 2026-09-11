<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * A single, generic in-app (database) notification used for all activity
 * across tickets, task boards, and the project tracker. The concrete kind of
 * event lives inside the payload (`domain`, `event`) rather than in separate
 * notification classes, so the bell can render every type uniformly.
 */
class ActivityNotification extends Notification
{
    public function __construct(public array $payload) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return $this->payload;
    }
}
