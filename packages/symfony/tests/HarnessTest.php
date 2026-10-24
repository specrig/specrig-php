<?php

declare(strict_types=1);

namespace Specrig\Symfony\Tests;

use App\Billing;
use App\Command\ConsumePaymentsCommand;
use App\Entity\Reminder;
use App\SendReminders;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Specrig\Amqp\Bus;
use Specrig\Backend;
use Specrig\Postgres;
use Specrig\Redis;
use Specrig\Rig;
use Specrig\Symfony\Harness;
use Symfony\Component\HttpClient\HttpClient;

final class Reminders extends Harness
{
    /** @var array<string, string|null> */
    public static array $constants = [];

    public function __construct(
        public readonly Backend $billing = new Backend('https://billing.reminders.test', setting: 'BILLING_URL', contract: '../docs/billing.yaml', failure: 'Error'),
        public readonly Postgres $database = new Postgres('DATABASE_URL'),
        public readonly Redis $cache = new Redis('REDIS_URL'),
        public readonly Bus $bus = new Bus('RABBIT_URL'),
    ) {
        parent::__construct();
    }

    public static function tests(): string
    {
        return dirname(__DIR__, 3) . '/samples/reminders/tests';
    }

    public function service(): string
    {
        return 'reminders';
    }

    protected function constants(): array
    {
        return self::$constants;
    }

    protected function running(): array
    {
        return [ConsumePaymentsCommand::class];
    }

    protected function allow(): array
    {
        return [Billing::class];
    }
}

final class HarnessTest extends TestCase
{
    private Reminders $reminders;

    protected function setUp(): void
    {
        Reminders::$constants = [];
        $this->reminders = new Reminders();
    }

    protected function tearDown(): void
    {
        $this->reminders->dispose();
    }

    public static function tearDownAfterClass(): void
    {
        Rig::endRun();
    }

    private function reminded(int $order): void
    {
        $entities = $this->reminders->get(EntityManagerInterface::class);
        $entities->persist(new Reminder($order, 120, new \DateTimeImmutable()));
        $entities->flush();
    }

    public function testACallNoStepAnsweredIsAFailureNamed(): void
    {
        try {
            $this->reminders->run(SendReminders::class);
        } catch (\Throwable) {
        }

        $this->assertSame(['billing was called where no step declared its answer: GET /invoices?status=unpaid'], $this->reminders->failures());
    }

    public function testARequestIsTracedByTheRouteItMatchedAndItsRefusal(): void
    {
        $this->reminders->http()->get('/reminders/8');

        $record = $this->reminders->trace->records()[0];
        $this->assertSame(['in', 'http', 'GET /reminders/{order}', 'HTTP 404 reminder_not_found'], [$record->dir, $record->kind, $record->name, $record->error]);
    }

    public function testARequestPastTheStandInsIsRefusedBeforeItLeavesAndNamed(): void
    {
        $this->reminders->boot();

        $refused = HttpClient::create()->request('GET', 'https://maps.example.com/tiles/1', ['timeout' => 5]);

        $this->assertSame(502, $refused->getStatusCode());
        $this->assertSame(['requests to an origin Reminders does not name: https://maps.example.com, past the stand-ins'], $this->reminders->failures());
    }

    public function testAnAnswerTheServiceDoesNotReadFailsTheBoot(): void
    {
        Reminders::$constants = ['REMINDER_LIMIT' => '1'];

        $this->expectExceptionMessage('Reminders answers REMINDER_LIMIT, which reminders does not read');
        $this->reminders->boot();
    }

    public function testAGrantedSeamIsWhatTheServiceGets(): void
    {
        $billing = new Billing(HttpClient::create());
        $this->reminders->replace(Billing::class, $billing);

        $this->assertSame($billing, $this->reminders->get(Billing::class));
    }

    public function testEveryCaseStartsFromAnEmptyDatabase(): void
    {
        $this->reminded(7);
        $this->reminders->dispose();

        $this->reminders = new Reminders();

        $this->assertNull($this->reminders->get(EntityManagerInterface::class)->getRepository(Reminder::class)->findOneBy(['order' => 7]));
    }

    public function testADatabaseFailsWithTheErrorAStepChose(): void
    {
        $this->reminded(7);
        $this->reminders->database->failWith(new \RuntimeException('the disk is full'));

        $this->expectExceptionMessage('the disk is full');
        $this->reminders->get(EntityManagerInterface::class)->getConnection()->executeQuery('SELECT 1');
    }
}
