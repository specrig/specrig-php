<?php

namespace App;

use Predis\Client;

// Billing may list an invoice as unpaid a while after it reports the invoice paid.
final class PaidOrders
{
    public function __construct(private readonly Client $redis)
    {
    }

    public function has(int $order): bool
    {
        return (bool) $this->redis->sismember('paid', (string) $order);
    }

    public function add(int $order): void
    {
        $this->redis->sadd('paid', [(string) $order]);
    }
}
