<?php

declare(strict_types=1);

namespace App\Notifications\Domain;

use App\Notifications\InAppMessage;
use App\Notifications\InAppNotification;

final class DomainAlert extends InAppNotification
{
    public function __construct(private readonly InAppMessage $message) {}

    public function inApp(object $notifiable): InAppMessage
    {
        return $this->message;
    }
}
