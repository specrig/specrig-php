<?php

declare(strict_types=1);

namespace Specrig\Tests;

use PHPUnit\Framework\TestCase;
use Specrig\Environment;
use Specrig\Postgres;
use Specrig\Postgres\Address;
use Specrig\Rig;
use Specrig\Write;

final class RefusalTest extends TestCase
{
    private const SCHEMA = <<<'SQL'
        create table states (id int generated always as identity primary key, name text not null);
        insert into states (name) values ('new'), ('active'), ('closed');
        create table vendors (id serial primary key, state_id int not null references states(id), name text not null);
        insert into vendors (state_id, name) values (1, 'R1');
        SQL;

    private Seam $seam;

    private Postgres $postgres;

    private Rigged $rig;

    private \PDO $database;

    public static function tearDownAfterClass(): void
    {
        Rig::endRun();
    }

    protected function setUp(): void
    {
        $this->seam = new Seam(fn () => self::connect()->exec(self::SCHEMA));
        $this->postgres = new Postgres('DATABASE_URL');
        $this->rig = new Rigged(database: $this->postgres, seam: $this->seam);
        $this->rig->boot();
        $this->database = self::connect();
    }

    protected function tearDown(): void
    {
        $this->rig->dispose();
    }

    private static function connect(): \PDO
    {
        return Address::parse((string) Environment::get('DATABASE_URL'))->pdo();
    }

    /** @return list<mixed> */
    private function column(string $query): array
    {
        return $this->database->query($query)->fetchAll(\PDO::FETCH_COLUMN);
    }

    private function refused(\PDO $connection, string $statement, string $message): void
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
        $this->postgres->refuse('vendors');

        foreach ([$this->database, self::connect()] as $connection) {
            $this->refused($connection, "insert into vendors (state_id, name) values (1, 'R2')", 'insert on vendors');
            $this->refused($connection, "insert into vendors (state_id, name) values (1, 'R2') returning id", 'insert on vendors');
            $this->refused($connection, "update vendors set name = 'renamed'", 'update on vendors');
            $this->refused($connection, 'delete from vendors', 'delete on vendors');
            $this->assertSame(['R1'], $connection->query('select name from vendors')->fetchAll(\PDO::FETCH_COLUMN));
        }
    }

    public function testARefusalLeavesTheOtherTablesAndTheWritesItDoesNotNameAlone(): void
    {
        $this->postgres->refuse('vendors', Write::Delete);

        $this->database->exec("insert into states (name) values ('extra')");
        $this->database->exec("update vendors set name = 'renamed'");
        $this->refused($this->database, 'delete from vendors', 'delete on vendors');
        $this->assertSame(['renamed'], $this->column('select name from vendors'));
    }

    public function testATableIsNamedWithItsSchema(): void
    {
        $this->postgres->refuse('public.vendors', Write::Delete);

        $this->refused($this->database, 'delete from vendors', 'delete on vendors');
    }

    public function testAQuotaLetsThatManyRowsThrough(): void
    {
        $this->postgres->refuseAfter('states', 1);

        $this->database->exec("insert into states (name) values ('first')");
        $this->refused($this->database, "insert into states (name) values ('second')", 'insert on states');
        $this->assertSame(['new', 'active', 'closed', 'first'], $this->column('select name from states order by id'));
    }

    public function testADeleteWithinTheQuotaDeletes(): void
    {
        $this->postgres->refuseAfter('vendors', 1, Write::Delete);

        $this->database->exec('delete from vendors');

        $this->assertSame([], $this->column('select name from vendors'));
    }

    public function testARolledBackRowGivesItsPlaceBack(): void
    {
        $this->postgres->refuseAfter('states', 1);
        $this->database->beginTransaction();
        $this->database->exec("insert into states (name) values ('undone')");
        $this->database->rollBack();

        $this->database->exec("insert into states (name) values ('kept')");

        $this->assertSame(['new', 'active', 'closed', 'kept'], $this->column('select name from states order by id'));
    }

    public function testRefusingAgainReplacesTheQuota(): void
    {
        $this->postgres->refuse('states');

        $this->postgres->refuseAfter('states', 1);

        $this->database->exec("insert into states (name) values ('first')");
        $this->assertSame(['new', 'active', 'closed', 'first'], $this->column('select name from states order by id'));
    }

    public function testACommitRefusalLetsTheStatementsThroughAndFailsTheCommit(): void
    {
        $this->postgres->refuseCommit('states');
        $writer = self::connect();

        $writer->beginTransaction();
        $writer->exec("insert into states (name) values ('extra')");
        try {
            $writer->commit();
            $this->fail('the commit went through');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('specrig: insert on states is refused', $e->getMessage());
        }

        $this->assertSame(['new', 'active', 'closed'], $this->column('select name from states order by id'));
    }

    public function testACommitRefusalLeavesATransactionThatDidNotWriteTheTableAlone(): void
    {
        $this->postgres->refuseCommit('states');

        $this->database->beginTransaction();
        $this->database->query('select name from states')->fetchAll();
        $this->database->exec("insert into vendors (state_id, name) values (1, 'R2')");
        $this->database->commit();

        $this->assertSame(['R1', 'R2'], $this->column('select name from vendors order by id'));
    }

    public function testARefusalAStepSetsBeforeTheBootWaitsForIt(): void
    {
        $postgres = new Postgres('DATABASE_URL');
        $rig = new Rigged(database: $postgres, seam: $this->seam);
        $postgres->refuse('vendors');

        $rig->boot();

        $this->refused($this->database, "insert into vendors (state_id, name) values (1, 'R2')", 'insert on vendors');
        $rig->dispose();
    }

    public function testATableTheDatabaseDoesNotHaveCannotBeRefused(): void
    {
        $this->expectExceptionMessage('cannot refuse writes to nothing');

        $this->postgres->refuse('nothing');
    }

    public function testHealLiftsTheRefusalsAndFailWithAndKeepsTheRows(): void
    {
        $this->database->exec("insert into states (name) values ('extra')");
        $this->postgres->refuse('vendors');
        $this->postgres->failWith(new \RuntimeException('the disk is full'));

        $this->postgres->heal();

        $this->assertSame(['fail the disk is full', 'heal'], $this->seam->calls);
        $this->database->exec("insert into vendors (state_id, name) values (1, 'R2')");
        $this->assertSame(['new', 'active', 'closed', 'extra'], $this->column('select name from states order by id'));
    }

    public function testHealEndsARefusedDatabaseAndAFaultCanComeAgain(): void
    {
        $this->postgres->failWith();
        $this->postgres->heal();
        $this->postgres->failWith();

        $this->assertSame(['refuse', 'heal', 'refuse'], $this->seam->calls);
    }

    public function testTheResetBeforeACaseLiftsTheRefusals(): void
    {
        $this->postgres->refuse('vendors');
        $this->rig->dispose();

        $this->rig = new Rigged(database: new Postgres('DATABASE_URL'), seam: $this->seam);
        $this->rig->boot();

        $this->database->exec("insert into vendors (state_id, name) values (1, 'R2')");
        $this->assertSame([false], $this->column("select exists (select 1 from information_schema.schemata where schema_name = 'specrig_refusal')"));
    }
}
