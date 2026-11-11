<?php

declare(strict_types=1);

namespace Specrig\Tests;

use PHPUnit\Framework\TestCase;
use Specrig\Postgres\Server;
use Specrig\Rig;
use Specrig\Sql\Address;

final class PostgresTest extends TestCase
{
    private const IMAGE = 'postgres:17-alpine';

    private const SEEDED = <<<'SQL'
        create table states (id int generated always as identity primary key, name text not null);
        insert into states (name) values ('new'), ('active'), ('closed');
        create table categories (id bigserial primary key, parent_id bigint references categories(id), name text not null, name_length int generated always as (length(name)) stored);
        insert into categories (parent_id, name) values (null, 'Master'), (1, 'Child');
        create table vendors (id serial primary key, state_id int not null references states(id), name text not null);
        insert into vendors (state_id, name) values (1, 'R1');
        create table audit (id serial primary key, what text not null);
        create function audited() returns trigger language plpgsql as $$ begin insert into audit (what) values (new.name); return new; end $$;
        create trigger vendors_audited after insert on vendors for each row execute function audited();
        create table orders (id serial primary key, vendor_id int not null references vendors(id));
        SQL;

    private \PDO $database;

    protected function tearDown(): void
    {
        Rig::endRun();
    }

    /** @param list<string> $keep */
    private function migrated(string $schema, array $keep = [], ?string $given = null): Server
    {
        $server = Server::start('specrig', $given, self::IMAGE);
        $this->database = Address::parse($server->address)->pdo();
        $server->once(fn () => $this->database->exec($schema), $keep);
        return $server;
    }

    // A database stem of its own, so the sweep reaches only databases this role can drop.
    private function plain(): string
    {
        $admin = Address::parse(Server::start('specrig', null, self::IMAGE)->address);
        $admin->pdo()->exec('DO $$ BEGIN CREATE ROLE specrig_plain LOGIN CREATEDB; EXCEPTION WHEN duplicate_object THEN NULL; END $$');
        return "postgresql://specrig_plain@{$admin->host}:{$admin->port}/specrig_plain";
    }

    /** @return list<mixed> */
    private function column(string $query): array
    {
        return $this->database->query($query)->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function testTheAddressStillAnswersToItsOldName(): void
    {
        $server = $this->migrated(self::SEEDED);

        $old = \Specrig\Postgres\Address::parse($server->address);

        $this->assertSame(['R1'], $old->pdo()->query('select name from vendors')->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testAResetPutsBackTheRowsTheMigrationsLeftAndNoOthers(): void
    {
        $server = $this->migrated(self::SEEDED);
        $this->database->exec("insert into states (name) values ('extra')");
        $this->database->exec("update vendors set name = 'renamed'");
        $this->database->exec('delete from categories where parent_id is not null');
        $this->database->exec('insert into orders (vendor_id) values (1)');

        $server->reset();

        $this->assertSame(['new', 'active', 'closed'], $this->column('select name from states order by id'));
        $this->assertSame(['Master', 'Child'], $this->column('select name from categories order by id'));
        $this->assertSame(['R1'], $this->column('select name from vendors'));
        $this->assertSame([], $this->column('select id from orders'));
    }

    public function testIdsGoOnAfterTheRowsPutBackAndStartOverWhereThereAreNone(): void
    {
        $server = $this->migrated(self::SEEDED);
        $this->database->exec("insert into states (name) values ('extra')");
        $this->database->exec('insert into orders (vendor_id) values (1)');

        $server->reset();

        $this->assertSame([4], $this->column("insert into states (name) values ('next') returning id"));
        $this->assertSame([2], $this->column("insert into vendors (state_id, name) values (1, 'R2') returning id"));
        $this->assertSame([1], $this->column('insert into orders (vendor_id) values (1) returning id'));
    }

    public function testAnOrderComesBackBeforeItsVendorAndATreeWithItsRoot(): void
    {
        $server = $this->migrated(self::SEEDED . "\ninsert into orders (vendor_id) values (1);");

        $server->reset();

        $this->assertSame([1], $this->column('select vendor_id from orders'));
        $this->assertSame([null, 1], $this->column('select parent_id from categories order by id'));
    }

    public function testPuttingRowsBackFiresNoTrigger(): void
    {
        $server = $this->migrated(self::SEEDED);

        $server->reset();

        $this->assertSame([], $this->column('select what from audit'));
    }

    public function testAGeneratedColumnComesBackRecomputed(): void
    {
        $server = $this->migrated(self::SEEDED);
        $this->database->exec("update categories set name = 'Renamed' where id = 1");

        $server->reset();

        $this->assertSame([6, 5], $this->column('select name_length from categories order by id'));
    }

    public function testMigrationsThatLeaveNoRowsLeaveNothingAside(): void
    {
        $server = $this->migrated('create table orders (id serial primary key)');
        $this->database->exec('insert into orders default values');

        $server->reset();

        $this->assertSame([1], $this->column('insert into orders default values returning id'));
        $this->assertSame([false], $this->column("select exists (select 1 from information_schema.schemata where schema_name = 'specrig_baseline')"));
    }

    public function testAPartitionedTableComesBackOnce(): void
    {
        $server = $this->migrated(<<<'SQL'
            create table events (id bigserial, at date not null, name text not null) partition by range (at);
            create table events_2026 partition of events for values from ('2026-01-01') to ('2027-01-01');
            insert into events (at, name) values ('2026-03-01', 'seeded');
            SQL);

        $server->reset();

        $this->assertSame(['seeded'], $this->column('select name from events'));
        $this->assertSame([2], $this->column("insert into events (at, name) values ('2026-04-01', 'next') returning id"));
    }

    public function testAKeptTableIsLeftAsTheCaseLeftIt(): void
    {
        $server = $this->migrated(self::SEEDED, ['states']);
        $this->database->exec("insert into states (name) values ('extra')");

        $server->reset();

        $this->assertSame(['new', 'active', 'closed', 'extra'], $this->column('select name from states order by id'));
        $this->assertSame([5], $this->column("insert into states (name) values ('next') returning id"));
    }

    public function testPuttingBackTheRowsTheMigrationsLeftNeedsASuperuser(): void
    {
        $this->expectExceptionMessage('did not migrate: putting back the rows the migrations left needs a superuser');
        $this->migrated(self::SEEDED, given: $this->plain());
    }

    public function testMigrationsThatWriteNoRowsNeedNoSuperuser(): void
    {
        $server = $this->migrated('create table orders (id serial primary key)', given: $this->plain());
        $this->database->exec('insert into orders default values');

        $server->reset();

        $this->assertSame([1], $this->column('insert into orders default values returning id'));
    }
}
