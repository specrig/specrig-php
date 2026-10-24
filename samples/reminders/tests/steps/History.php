<?php

declare(strict_types=1);

namespace App\Tests\Steps;

use App\Entity\Reminder;
use App\Tests\Internal\Harness;
use Behat\Behat\Context\Context;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use Doctrine\ORM\EntityManagerInterface;
use Specrig\Http\Response;
use Webmozart\Assert\Assert;

final class History implements Context
{
    private ?Response $shown = null;

    public function __construct(private readonly Harness $harness)
    {
    }

    #[Given('order :order was reminded of :amount')]
    public function reminded(int $order, int $amount): void
    {
        $entities = $this->harness->get(EntityManagerInterface::class);
        $entities->persist(new Reminder($order, $amount, new \DateTimeImmutable('-1 hour')));
        $entities->flush();
    }

    #[Given("the reminders' database is down")]
    public function down(): void
    {
        $this->harness->database->failWith();
    }

    #[When('someone asks for the reminder of order :order')]
    public function ask(int $order): void
    {
        $this->shown = $this->harness->http()->get("/reminders/{$order}");
    }

    #[Then('they see a reminder of :amount')]
    public function shows(int $amount): void
    {
        Assert::same($this->shown?->status, 200, $this->shown?->body ?? '');
        Assert::same($this->shown->json()['amount'], $amount);
    }

    #[Then('they are told the order was never reminded of')]
    public function never(): void
    {
        Assert::same($this->shown?->status, 404, $this->shown?->body ?? '');
    }

    #[Then('they are told to come back later')]
    public function later(): void
    {
        Assert::same($this->shown?->status, 503, $this->shown?->body ?? '');
    }
}
