<?php

declare(strict_types=1);

namespace App\Tests\Steps;

use App\Message\RemindersDue;
use App\SendReminders;
use App\Tests\Internal\Harness;
use Behat\Behat\Context\Context;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use Specrig\Amqp\Settled;
use Webmozart\Assert\Assert;

final class Reminders implements Context
{
    /** @var list<Settled> */
    private array $settled = [];

    public function __construct(private readonly Harness $harness)
    {
    }

    #[Given('billing holds an unpaid invoice of :amount for order :order, due :when')]
    public function unpaid(int $amount, int $order, string $when): void
    {
        $dueOn = match ($when) {
            'yesterday' => date('Y-m-d', strtotime('-1 day')),
            'tomorrow' => date('Y-m-d', strtotime('+1 day')),
        };
        $this->harness->billing->replyTo('GET', '/invoices', ['status' => 'unpaid'], 200, [['order' => $order, 'amount' => $amount, 'dueOn' => $dueOn]]);
    }

    #[Given('billing holds unpaid invoices of :amount for order :order and :other for order :otherOrder, both due yesterday')]
    public function bothUnpaid(int $amount, int $order, int $other, int $otherOrder): void
    {
        $dueOn = date('Y-m-d', strtotime('-1 day'));
        $this->harness->billing->replyTo('GET', '/invoices', ['status' => 'unpaid'], 200, [
            ['order' => $order, 'amount' => $amount, 'dueOn' => $dueOn],
            ['order' => $otherOrder, 'amount' => $other, 'dueOn' => $dueOn],
        ]);
    }

    #[Given('billing accepts reminders')]
    public function accepts(): void
    {
        $this->harness->billing->reply('POST', '/reminders', 202);
    }

    #[Given('billing has reported order :order paid')]
    public function reported(int $order): void
    {
        Assert::same($this->harness->bus->deliver('invoices.paid', ['order' => $order]), [Settled::Acked]);
    }

    #[When('the reminders run')]
    public function run(): void
    {
        $this->harness->run(SendReminders::class);
    }

    #[When('the scheduler fires the reminders')]
    public function fire(): void
    {
        $this->harness->run(RemindersDue::class);
    }

    #[When('someone runs the reminders by hand')]
    public function byHand(): void
    {
        Assert::same($this->harness->command('app:remind'), 0, $this->harness->output());
    }

    #[When('the worker runs')]
    public function work(): void
    {
        foreach ($this->harness->work() as $worked) {
            Assert::false($worked->failed(), "{$worked->job}: {$worked->error?->getMessage()}");
        }
    }

    #[When('billing reports order :order paid')]
    public function reports(int $order): void
    {
        $this->settled = $this->harness->bus->deliver('invoices.paid', ['order' => $order]);
    }

    #[When('billing reports a payment nobody can read')]
    public function unreadable(): void
    {
        $this->settled = $this->harness->bus->deliver('invoices.paid', 'a payment, in prose');
    }

    #[Then('billing is asked to remind the customer of :amount for order :order')]
    public function reminded(int $amount, int $order): void
    {
        $requests = $this->harness->billing->requests('POST', '/reminders');
        Assert::count($requests, 1);
        Assert::same($requests[0]->body(), ['order' => $order, 'amount' => $amount]);
    }

    #[Then('billing is asked to remind one customer')]
    public function one(): void
    {
        Assert::count($this->harness->billing->requests('POST', '/reminders'), 1);
    }

    #[Then('billing is asked to remind nobody')]
    public function nobody(): void
    {
        Assert::isEmpty($this->harness->billing->requests('POST', '/reminders'));
    }

    #[Then('the reminder of :amount for order :order is announced')]
    public function announced(int $amount, int $order): void
    {
        $published = $this->harness->bus->published('reminders.sent');
        Assert::count($published, 1);
        Assert::same($published[0]->body(), ['order' => $order, 'amount' => $amount]);
    }

    #[Then('the report is acknowledged')]
    public function acknowledged(): void
    {
        Assert::same($this->settled, [Settled::Acked]);
    }

    #[Then('the report is dropped')]
    public function dropped(): void
    {
        Assert::same($this->settled, [Settled::Dropped]);
    }
}
