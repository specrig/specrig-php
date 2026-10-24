<?php

declare(strict_types=1);

namespace Tests\Steps;

use App\Models\Order;
use Behat\Behat\Context\Context;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use Specrig\Http\Response;
use Tests\Internal\Harness;
use Webmozart\Assert\Assert;

final class Orders implements Context
{
    private ?Response $opened = null;

    public function __construct(private readonly Harness $harness)
    {
    }

    #[Given('order :order of :amount is placed')]
    public function placed(int $order, int $amount): void
    {
        $this->harness->boot();
        Order::create(['id' => $order, 'amount' => $amount, 'paid' => false]);
    }

    #[Given('billing holds an invoice of :amount for order :order')]
    public function invoice(int $amount, int $order): void
    {
        $this->harness->billing->reply('GET', "/invoices/{$order}", 200, ['order' => $order, 'amount' => $amount, 'dueOn' => date('Y-m-d', strtotime('+14 days'))]);
    }

    #[Given("the shop's database is down")]
    public function databaseDown(): void
    {
        $this->harness->database->failWith();
    }

    #[Given('billing does not answer in time')]
    public function billingSlow(): void
    {
        $this->harness->billing->timeOut('GET', '/invoices/7');
    }

    #[When('the customer opens order :order')]
    public function open(int $order): void
    {
        $this->opened = $this->harness->http()->get("/api/orders/{$order}");
    }

    #[Then('they see :amount to pay')]
    public function toPay(int $amount): void
    {
        Assert::same($this->opened?->status, 200, $this->opened?->body ?? '');
        Assert::same($this->opened->json()['toPay'], $amount);
    }

    #[Then('they are told there is no such order')]
    public function noSuchOrder(): void
    {
        Assert::same($this->opened?->status, 404);
    }

    #[Then('they are told to come back later')]
    public function comeBack(): void
    {
        Assert::same($this->opened?->status, 503, $this->opened?->body ?? '');
    }
}
