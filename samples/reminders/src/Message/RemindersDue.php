<?php

namespace App\Message;

final class RemindersDue
{
    public function __construct(public readonly int $limit)
    {
    }
}
