<?php

declare(strict_types=1);

namespace Specrig\Tests;

use PHPUnit\Framework\TestCase;
use Specrig\Database;
use Specrig\Environment;
use Specrig\Rig;
use Specrig\Sql\Address;
use Specrig\Write;

abstract class Refusals extends TestCase
{
    protected Seam $seam;

    protected Database $database;

    protected Rigged $rig;

    protected \PDO $connection;

    /** @return list<string> states, with three rows, and vendors, with one that names the first state */
    abstract protected static function schema(): array;

    abstract protected static function database(): Database;

    abstract protected static function leftovers(): string;

    public static function tearDownAfterClass(): void
    {
        Rig::endRun();
    }

    protected function setUp(): void
    {
        $this->seam = new Seam(function () {
            $connection = self::connect();
            foreach (static::schema() as $statement) {
                $connection->exec($statement);
            }
        });
        $this->database = static::database();
        $this->rig = new Rigged(database: $this->database, seam: $this->seam);
        $this->rig->boot();
        $this->connection = self::connect();
    }

    protected function tearDown(): void
    {
        $this->rig->dispose();
    }

    protected static function connect(): \PDO
    {
        return Address::parse((string) Environment::get('DATABASE_URL'))->pdo();
    }

    /** @return list<mixed> */
    protected function column(string $query): array
    {
        return $this->connection->query($query)->fetchAll(\PDO::FETCH_COLUMN);
    }

    protected function refused(\PDO $connection, string $statement, string $message): void
    {
        try {
            $connection->query($statement);
            $this->fail("{$statement} went through");
        } catch (\PDOException $e) {
            $this->assertStringContainsString("specrig: {$message} is refused", $e->getMessage());
        }
    }

    public function testARefusedTableRefusesEveryWriteOfEveryConnectionAndStillReads(): void
    {
        $this->database->refuse('vendors');

        foreach ([$this->connection, self::connect()] as $connection) {
            $this->refused($connection, "insert into vendors (state_id, name) values (1, 'R2')", 'insert on vendors');
            $this->refused($connection, "update vendors set name = 'renamed'", 'update on vendors');
            $this->refused($connection, 'delete from vendors', 'delete on vendors');
            $this->assertSame(['R1'], $connection->query('select name from vendors')->fetchAll(\PDO::FETCH_COLUMN));
        }
    }

    public function testARefusalLeavesTheOtherTablesAndTheWritesItDoesNotNameAlone(): void
    {
        $this->database->refuse('vendors', Write::Delete);

        $this->connection->exec("insert into states (name) values ('extra')");
        $this->connection->exec("update vendors set name = 'renamed'");
        $this->refused($this->connection, 'delete from vendors', 'delete on vendors');
        $this->assertSame(['renamed'], $this->column('select name from vendors'));
    }

    public function testAQuotaLetsThatManyRowsThrough(): void
    {
        $this->database->refuseAfter('states', 1);

        $this->connection->exec("insert into states (name) values ('first')");
        $this->refused($this->connection, "insert into states (name) values ('second')", 'insert on states');
        $this->assertSame(['new', 'active', 'closed', 'first'], $this->column('select name from states order by id'));
    }

    public function testAQuotaIsSharedByTheWritesItNames(): void
    {
        $this->database->refuseAfter('states', 1);

        $this->connection->exec("update states set name = 'renamed' where id = 1");
        $this->refused($this->connection, "insert into states (name) values ('second')", 'insert on states');
    }

    public function testADeleteWithinTheQuotaDeletes(): void
    {
        $this->database->refuseAfter('vendors', 1, Write::Delete);

        $this->connection->exec('delete from vendors');

        $this->assertSame([], $this->column('select name from vendors'));
    }

    public function testARolledBackRowGivesItsPlaceBack(): void
    {
        $this->database->refuseAfter('states', 1);
        $this->connection->beginTransaction();
        $this->connection->exec("insert into states (name) values ('undone')");
        $this->connection->rollBack();

        $this->connection->exec("insert into states (name) values ('kept')");

        $this->assertSame(['new', 'active', 'closed', 'kept'], $this->column('select name from states order by id'));
    }

    public function testRefusingAgainReplacesTheQuota(): void
    {
        $this->database->refuse('states');

        $this->database->refuseAfter('states', 1);

        $this->connection->exec("insert into states (name) values ('first')");
        $this->assertSame(['new', 'active', 'closed', 'first'], $this->column('select name from states order by id'));
    }

    public function testARefusalAStepSetsBeforeTheBootWaitsForIt(): void
    {
        $database = static::database();
        $rig = new Rigged(database: $database, seam: $this->seam);
        $database->refuse('vendors');

        $rig->boot();

        $this->refused($this->connection, "insert into vendors (state_id, name) values (1, 'R2')", 'insert on vendors');
        $rig->dispose();
    }

    public function testATableTheDatabaseDoesNotHaveCannotBeRefused(): void
    {
        $this->expectExceptionMessage('cannot refuse writes to nothing');

        $this->database->refuse('nothing');
    }

    public function testHealLiftsTheRefusalsAndFailWithAndKeepsTheRows(): void
    {
        $this->connection->exec("insert into states (name) values ('extra')");
        $this->database->refuse('vendors');
        $this->database->failWith(new \RuntimeException('the disk is full'));

        $this->database->heal();

        $this->assertSame(['fail the disk is full', 'heal'], $this->seam->calls);
        $this->connection->exec("insert into vendors (state_id, name) values (1, 'R2')");
        $this->assertSame(['new', 'active', 'closed', 'extra'], $this->column('select name from states order by id'));
    }

    public function testHealEndsARefusedDatabaseAndAFaultCanComeAgain(): void
    {
        $this->database->failWith();
        $this->database->heal();
        $this->database->failWith();

        $this->assertSame(['refuse', 'heal', 'refuse'], $this->seam->calls);
    }

    public function testTheResetBeforeACaseLiftsTheRefusals(): void
    {
        $this->database->refuse('vendors');
        $this->rig->dispose();

        $this->rig = new Rigged(database: static::database(), seam: $this->seam);
        $this->rig->boot();

        $this->connection->exec("insert into vendors (state_id, name) values (1, 'R2')");
        $this->assertSame([], $this->column(static::leftovers()));
    }
}
