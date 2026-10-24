<?php

declare(strict_types=1);

namespace Tests\Steps;

use App\Models\Order;
use Behat\Behat\Context\Context;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use Specrig\Amqp\Settled;
use Specrig\Http\Response;
use Tests\Internal\Harness;
use Webmozart\Assert\Assert;

final class Placing implements Context
{
    private ?Response $placed = null;

    /** @var list<Settled> */
    private array $settled = [];

    public function __construct(private readonly Harness $harness)
    {
    }

    #[Given('billing accepts invoices')]
    public function accepts(): void
    {
        $this->harness->billing->reply('POST', '/invoices', 201);
    }

    #[Given('billing has reported order :order paid')]
    public function reported(int $order): void
    {
        Assert::same($this->harness->bus->deliver('invoices.paid', ['order' => $order]), [Settled::Acked]);
    }

    #[When('the customer places order :order of :amount')]
    public function place(int $order, int $amount): void
    {
        $this->placed = $this->harness->http()->post('/api/orders', ['id' => $order, 'amount' => $amount]);
    }

    #[When("the shop's worker runs")]
    public function work(): void
    {
        foreach ($this->harness->work() as $worked) {
            Assert::false($worked->failed(), "{$worked->job}: {$worked->error?->getMessage()}");
        }
    }

    #[When('billing reports a payment nobody can read')]
    public function unreadable(): void
    {
        $this->settled = $this->harness->bus->deliver('invoices.paid', 'a payment, in prose');
    }

    #[When('someone cancels order :order by hand')]
    public function cancel(int $order): void
    {
        Assert::same($this->harness->command('orders:cancel', (string) $order), 0, $this->harness->output());
    }

    #[Then('order :order of :amount is kept unpaid')]
    public function kept(int $order, int $amount): void
    {
        Assert::same($this->placed?->status, 201, $this->placed?->body ?? '');
        $kept = Order::find($order);
        Assert::same([$kept?->amount, $kept?->paid], [$amount, false]);
    }

    #[Then('the placement of order :order for :amount is announced')]
    public function announced(int $order, int $amount): void
    {
        $published = $this->harness->bus->published('orders.placed');
        Assert::count($published, 1);
        Assert::same($published[0]->body(), ['order' => $order, 'amount' => $amount]);
    }

    #[Then('billing is asked to invoice nothing yet')]
    public function notYet(): void
    {
        Assert::isEmpty($this->harness->billing->requests('POST', '/invoices'));
    }

    #[Then('billing is asked to invoice order :order for :amount')]
    public function invoiced(int $order, int $amount): void
    {
        $requests = $this->harness->billing->requests('POST', '/invoices');
        Assert::count($requests, 1);
        Assert::same($requests[0]->body(), ['order' => $order, 'amount' => $amount]);
    }

    #[Then('they are told the order is invalid')]
    public function invalid(): void
    {
        Assert::same($this->placed?->status, 422);
    }

    #[Then('the report is dropped')]
    public function dropped(): void
    {
        Assert::same($this->settled, [Settled::Dropped]);
    }

    #[Then('order :order is gone')]
    public function gone(int $order): void
    {
        Assert::null(Order::find($order));
    }

    #[Then('the cancellation of order :order is announced')]
    public function cancelled(int $order): void
    {
        $published = $this->harness->bus->published('orders.cancelled');
        Assert::count($published, 1);
        Assert::same($published[0]->body(), ['order' => $order]);
    }
}
