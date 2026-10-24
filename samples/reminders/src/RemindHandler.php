<?php

namespace App;

use App\Entity\Reminder;
use App\Message\Remind;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RemindHandler
{
    public function __construct(
        private readonly Billing $billing,
        private readonly Announcer $announcer,
        private readonly EntityManagerInterface $entities,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(Remind $remind): void
    {
        $this->billing->remind($remind->order, $remind->amount);
        $this->entities->persist(new Reminder($remind->order, $remind->amount, $this->clock->now()));
        $this->entities->flush();
        $this->announcer->sent($remind->order, $remind->amount);
    }
}
