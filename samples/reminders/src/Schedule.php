<?php

namespace App;

use App\Message\RemindersDue;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as Recurring;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

#[AsSchedule]
final class Schedule implements ScheduleProviderInterface
{
    public function __construct(#[Autowire(env: 'int:REMINDERS_LIMIT')] private readonly int $limit)
    {
    }

    public function getSchedule(): Recurring
    {
        return (new Recurring())->add(RecurringMessage::every('1 hour', new RemindersDue($this->limit)));
    }
}
