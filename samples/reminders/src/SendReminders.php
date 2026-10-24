<?php

namespace App;

use App\Message\Remind;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class SendReminders
{
    public function __construct(
        private readonly Billing $billing,
        private readonly PaidOrders $paid,
        private readonly MessageBusInterface $bus,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(int $limit = PHP_INT_MAX): int
    {
        $today = $this->clock->now()->format('Y-m-d');
        $sent = 0;
        foreach ($this->billing->unpaid() as $invoice) {
            if ($sent >= $limit) {
                break;
            }
            if ($invoice['dueOn'] < $today && !$this->paid->has($invoice['order'])) {
                $this->bus->dispatch(new Remind($invoice['order'], $invoice['amount']));
                $sent++;
            }
        }
        return $sent;
    }
}
