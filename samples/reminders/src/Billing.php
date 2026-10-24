<?php

namespace App;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class Billing
{
    public function __construct(private readonly HttpClientInterface $billingClient)
    {
    }

    /** @return list<array{order: int, amount: int, dueOn: string}> */
    public function unpaid(): array
    {
        return $this->billingClient->request('GET', '/invoices', ['query' => ['status' => 'unpaid']])->toArray();
    }

    public function remind(int $order, int $amount): void
    {
        $this->billingClient->request('POST', '/reminders', ['json' => ['order' => $order, 'amount' => $amount]])->getHeaders();
    }
}
