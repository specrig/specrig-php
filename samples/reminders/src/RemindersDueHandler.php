<?php

namespace App;

use App\Message\RemindersDue;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RemindersDueHandler
{
    public function __construct(private readonly SendReminders $send)
    {
    }

    public function __invoke(RemindersDue $due): void
    {
        ($this->send)($due->limit);
    }
}
