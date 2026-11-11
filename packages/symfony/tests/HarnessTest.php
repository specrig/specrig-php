<?php

declare(strict_types=1);

namespace Specrig\Symfony\Tests;

use App\Billing;
use App\Command\ConsumePaymentsCommand;
use App\Entity\Reminder;
use App\PaidOrders;
use App\SendReminders;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Predis\PredisException;
use Specrig\Amqp\Bus;
use Specrig\Backend;
use Specrig\Database;
use Specrig\Databases;
use Specrig\Environment;
use Specrig\Mysql;
use Specrig\Postgres;
use Specrig\Redis;
use Specrig\Rig;
use Specrig\Sqlite;
use Specrig\Symfony\Harness;
use Specrig\Write;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

final class Reminders extends Harness
{
    /** @var array<string, string|null> */
    public static array $constants = [];

    public function __construct(
        public readonly Backend $billing = new Backend('https://billing.reminders.test', setting: 'BILLING_URL', contract: '../docs/billing.yaml', failure: 'Error'),
        public readonly Database $database = new Postgres('DATABASE_URL'),
        public readonly Redis $cache = new Redis('REDIS_URL'),
        public readonly Bus $bus = new Bus('RABBIT_URL'),
    ) {
        parent::__construct();
    }

    public static function tests(): string
    {
        return dirname(__DIR__, 3) . '/samples/reminders/tests';
    }

    // The sample's migration is Postgres's SQL; its mapping makes the schema on any database.
    public function databases(): Databases
    {
        return $this->database instanceof Postgres ? parent::databases() : new Mapped(parent::databases(), $this);
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

final class Mapped implements Databases
{
    public function __construct(private readonly Databases $inner, private readonly Harness $harness)
    {
    }

    public function migrate(): void
    {
        [$code, $output] = $this->harness->console('doctrine:schema:create');
        if ($code !== 0) {
            throw new \RuntimeException(trim($output));
        }
    }

    public function bookkeeping(): array
    {
        return $this->inner->bookkeeping();
    }

    public function refuse(string $address, string $refusing): void
    {
        $this->inner->refuse($address, $refusing);
    }

    public function fail(string $address, \Throwable $error): void
    {
        $this->inner->fail($address, $error);
    }

    public function heal(string $address, string $refusing): void
    {
        $this->inner->heal($address, $refusing);
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

    public function testTheHeadersAStepGaveAReplyReachTheServicesClient(): void
    {
        $this->reminders->billing->replyTo('GET', '/invoices', ['status' => 'unpaid'], 200, [], ['X-Total' => '0']);

        $response = $this->reminders->get('billing.client')->request('GET', '/invoices', ['query' => ['status' => 'unpaid']]);

        $this->assertSame([['0'], []], [$response->getHeaders()['x-total'], $response->toArray()]);
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

        try {
            HttpClient::create()->request('GET', 'https://maps.example.com/tiles/1', ['timeout' => 5])->getContent();
            $this->fail('the request went past the stand-ins');
        } catch (ExceptionInterface) {
        }

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

    public function testARedisThatFailsCutsTheClientsTheServiceBuiltAndRefusesNewOnesUntilTheNextCase(): void
    {
        $paid = $this->reminders->get(PaidOrders::class);
        $paid->add(7);
        $this->assertTrue($paid->has(7));

        $this->reminders->cache->failWith();

        foreach ([fn () => $paid->has(7), fn () => (new Client((string) Environment::get('REDIS_URL')))->ping()] as $call) {
            try {
                $call();
                $this->fail('redis answered');
            } catch (PredisException) {
            }
        }
        $this->reminders->dispose();

        $this->reminders = new Reminders();
        $this->assertFalse($this->reminders->get(PaidOrders::class)->has(7));
    }

    public function testARedisThatFailsBeforeTheBootLetsTheServiceBootAndRefusesItAfterwards(): void
    {
        $this->reminders->cache->failWith();

        $paid = $this->reminders->get(PaidOrders::class);

        $this->expectException(PredisException::class);
        $paid->has(7);
    }

    public function testADatabaseThatComesBackAnswersAgainWithTheRowsItHeld(): void
    {
        $this->reminded(7);
        $connection = $this->reminders->get(EntityManagerInterface::class)->getConnection();
        $this->reminders->database->failWith();
        try {
            $connection->executeQuery('SELECT 1');
            $this->fail('the database answered');
        } catch (ConnectionException) {
        }

        $this->reminders->database->heal();

        $this->assertSame(1, $connection->fetchOne('SELECT count(*) FROM reminders'));
    }

    public function testAFaultGivenTheOldAddressPointsTheServiceAway(): void
    {
        $connection = $this->reminders->get(EntityManagerInterface::class)->getConnection();
        $address = (string) Environment::get('DATABASE_URL');

        $this->reminders->faulted($address, \Specrig\Postgres\Address::parse($address)->with(host: '127.0.0.1', port: 1), null);
        $connection->close();

        $this->expectException(ConnectionException::class);
        $connection->executeQuery('SELECT 1');
    }

    public function testADatabaseThatFailedWithAnErrorComesBackToo(): void
    {
        $this->reminded(7);
        $connection = $this->reminders->get(EntityManagerInterface::class)->getConnection();
        $this->reminders->database->failWith(new \RuntimeException('the disk is full'));
        try {
            $connection->executeQuery('SELECT 1');
            $this->fail('the database answered');
        } catch (\RuntimeException $e) {
            $this->assertSame('the disk is full', $e->getMessage());
        }

        $this->reminders->database->heal();

        $this->assertSame(1, $connection->fetchOne('SELECT count(*) FROM reminders'));
    }

    public function testATableThatRefusesItsWritesRefusesTheServiceUntilTheDatabaseComesBack(): void
    {
        $this->reminded(7);
        $connection = $this->reminders->get(EntityManagerInterface::class)->getConnection();
        $insert = "INSERT INTO reminders (order_id, amount, sent_at) VALUES (8, 80, '2026-11-10 12:00:00')";
        $this->reminders->database->refuse('reminders', Write::Insert);

        try {
            $connection->executeStatement($insert);
            $this->fail('the insert went through');
        } catch (DriverException $e) {
            $this->assertStringContainsString('specrig: insert on reminders is refused', $e->getMessage());
        }
        $this->assertSame(1, $connection->fetchOne('SELECT count(*) FROM reminders'));

        $this->reminders->database->heal();

        $this->assertSame(1, $connection->executeStatement($insert));
    }

    /** @return iterable<string, array{\Closure(): Database}> */
    public static function databases(): iterable
    {
        yield 'mysql' => [fn () => new Mysql('DATABASE_URL')];
        yield 'sqlite' => [fn () => new Sqlite('DATABASE_URL')];
    }

    /** @param \Closure(): Database $database */
    private function on(\Closure $database): void
    {
        $this->reminders->dispose();
        $this->reminders = new Reminders(database: $database());
    }

    #[DataProvider('databases')]
    public function testEveryCaseStartsFromAnEmptyMysqlOrSqliteDatabase(\Closure $database): void
    {
        $this->on($database);
        $this->reminded(7);
        $this->reminders->dispose();

        $this->reminders = new Reminders(database: $database());

        $this->assertNull($this->reminders->get(EntityManagerInterface::class)->getRepository(Reminder::class)->findOneBy(['order' => 7]));
    }

    #[DataProvider('databases')]
    public function testAMysqlOrSqliteDatabaseThatComesBackAnswersAgainWithTheRowsItHeld(\Closure $database): void
    {
        $this->on($database);
        $this->reminded(7);
        $connection = $this->reminders->get(EntityManagerInterface::class)->getConnection();
        $this->reminders->database->failWith();
        try {
            $connection->executeQuery('SELECT 1');
            $this->fail('the database answered');
        } catch (ConnectionException) {
        }

        $this->reminders->database->heal();

        $this->assertSame(1, $connection->fetchOne('SELECT count(*) FROM reminders'));
    }

    #[DataProvider('databases')]
    public function testAMysqlOrSqliteDatabaseThatFailedWithAnErrorComesBackToo(\Closure $database): void
    {
        $this->on($database);
        $this->reminded(7);
        $connection = $this->reminders->get(EntityManagerInterface::class)->getConnection();
        $this->reminders->database->failWith(new \RuntimeException('the disk is full'));
        try {
            $connection->executeQuery('SELECT 1');
            $this->fail('the database answered');
        } catch (\RuntimeException $e) {
            $this->assertSame('the disk is full', $e->getMessage());
        }

        $this->reminders->database->heal();

        $this->assertSame(1, $connection->fetchOne('SELECT count(*) FROM reminders'));
    }

    #[DataProvider('databases')]
    public function testAMysqlOrSqliteTableThatRefusesItsWritesRefusesTheServiceUntilTheDatabaseComesBack(\Closure $database): void
    {
        $this->on($database);
        $this->reminded(7);
        $connection = $this->reminders->get(EntityManagerInterface::class)->getConnection();
        $insert = "INSERT INTO reminders (order_id, amount, sent_at) VALUES (8, 80, '2026-11-10 12:00:00')";
        $this->reminders->database->refuse('reminders', Write::Insert);

        try {
            $connection->executeStatement($insert);
            $this->fail('the insert went through');
        } catch (DriverException $e) {
            $this->assertStringContainsString('specrig: insert on reminders is refused', $e->getMessage());
        }
        $this->assertSame(1, $connection->fetchOne('SELECT count(*) FROM reminders'));

        $this->reminders->database->heal();

        $this->assertSame(1, $connection->executeStatement($insert));
    }
}
