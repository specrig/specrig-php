<?php

declare(strict_types=1);

namespace Specrig\Tests;

use PHPUnit\Framework\TestCase;
use Specrig\Rig;
use Specrig\Sql\Address;
use Specrig\Sqlite\File;

final class SqliteTest extends TestCase
{
    private const SEEDED = <<<'SQL'
        create table states (id integer primary key autoincrement, name text not null);
        insert into states (name) values ('new'), ('active'), ('closed');
        create table categories (id integer primary key, parent_id integer references categories(id), name text not null,
            name_length int generated always as (length(name)) stored);
        insert into categories (parent_id, name) values (null, 'Master'), (1, 'Child');
        create table vendors (id integer primary key, state_id int not null references states(id), name text not null);
        insert into vendors (state_id, name) values (1, 'R1');
        create table audit (id integer primary key, what text not null);
        create trigger vendors_audited after insert on vendors begin insert into audit (what) values (new.name); end;
        create trigger vendors_forgotten after delete on vendors begin insert into audit (what) values ('-' || old.name); end;
        create table orders (id integer primary key, vendor_id int not null references vendors(id));
        SQL;

    private \PDO $database;

    protected function tearDown(): void
    {
        Rig::endRun();
    }

    /** @param list<string> $keep */
    private function migrated(string $schema, array $keep = []): File
    {
        $file = File::start('specrig');
        $this->database = Address::parse($file->address)->pdo();
        $file->once(fn () => $this->database->exec($schema), $keep);
        return $file;
    }

    /** @return list<mixed> */
    private function column(string $query): array
    {
        return $this->database->query($query)->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function testAResetPutsBackTheRowsTheMigrationsLeftAndNoOthers(): void
    {
        $file = $this->migrated(self::SEEDED);
        $this->database->exec("insert into states (name) values ('extra')");
        $this->database->exec("update vendors set name = 'renamed'");
        $this->database->exec('delete from categories where parent_id is not null');
        $this->database->exec('insert into orders (vendor_id) values (1)');

        $file->reset();

        $this->assertSame(['new', 'active', 'closed'], $this->column('select name from states order by id'));
        $this->assertSame(['Master', 'Child'], $this->column('select name from categories order by id'));
        $this->assertSame(['R1'], $this->column('select name from vendors'));
        $this->assertSame([], $this->column('select id from orders'));
    }

    public function testIdsGoOnAfterTheRowsPutBackAndStartOverWhereThereAreNone(): void
    {
        $file = $this->migrated(self::SEEDED);
        $this->database->exec("insert into states (name) values ('extra')");
        $this->database->exec('insert into orders (vendor_id) values (1)');

        $file->reset();

        $this->assertSame([4], $this->column("insert into states (name) values ('next') returning id"));
        $this->assertSame([2], $this->column("insert into vendors (state_id, name) values (1, 'R2') returning id"));
        $this->assertSame([1], $this->column('insert into orders (vendor_id) values (1) returning id'));
    }

    public function testAnOrderComesBackBeforeItsVendorAndATreeWithItsRoot(): void
    {
        $file = $this->migrated(self::SEEDED . "\ninsert into orders (vendor_id) values (1);");

        $file->reset();

        $this->assertSame([1], $this->column('select vendor_id from orders'));
        $this->assertSame([null, 1], $this->column('select parent_id from categories order by id'));
    }

    public function testPuttingRowsBackFiresNoTriggerAndKeepsThem(): void
    {
        $file = $this->migrated(self::SEEDED);

        $file->reset();

        $this->assertSame([], $this->column('select what from audit'));
        $this->database->exec("insert into vendors (state_id, name) values (1, 'R2')");
        $this->database->exec("delete from vendors where name = 'R2'");
        $this->assertSame(['R2', '-R2'], $this->column('select what from audit order by id'));
    }

    public function testAGeneratedColumnComesBackRecomputed(): void
    {
        $file = $this->migrated(self::SEEDED);
        $this->database->exec("update categories set name = 'Renamed' where id = 1");

        $file->reset();

        $this->assertSame([6, 5], $this->column('select name_length from categories order by id'));
    }

    public function testMigrationsThatLeaveNoRowsLeaveNothingAside(): void
    {
        $file = $this->migrated('create table orders (id integer primary key autoincrement)');
        $this->database->exec('insert into orders default values');

        $file->reset();

        $this->assertSame([1], $this->column('insert into orders default values returning id'));
    }

    public function testMigrationsThatMakeNoTablesLeaveNothingToReset(): void
    {
        $file = $this->migrated('select 1');

        $file->reset();

        $this->assertSame([], $this->column("select name from sqlite_schema where type = 'table'"));
    }

    public function testAKeptTableIsLeftAsTheCaseLeftIt(): void
    {
        $file = $this->migrated(self::SEEDED, ['states']);
        $this->database->exec("insert into states (name) values ('extra'), ('gone')");
        $this->database->exec("delete from states where name = 'gone'");

        $file->reset();

        $this->assertSame(['new', 'active', 'closed', 'extra'], $this->column('select name from states order by id'));
        $this->assertSame([6], $this->column("insert into states (name) values ('next') returning id"));
        $this->assertSame([6], $this->column("select seq from sqlite_sequence where name = 'states'"));
    }

    public function testAVirtualTableIsLeftAsTheCaseLeftIt(): void
    {
        $file = $this->migrated(self::SEEDED . <<<'SQL'
            create virtual table notes using fts5(body);
            insert into notes (body) values ('seeded');
            create virtual table boxes using rtree(id, low, high);
            insert into boxes values (1, 0, 1);
            SQL);
        $this->database->exec("insert into notes (body) values ('written')");
        $this->database->exec('insert into boxes values (2, 1, 2)');
        $this->database->exec("insert into states (name) values ('extra')");

        $file->reset();

        $this->assertSame(['seeded', 'written'], $this->column("select body from notes where notes match 'seeded OR written' order by rowid"));
        $this->assertSame([1, 2], $this->column('select id from boxes where low >= 0 order by id'));
        $this->assertSame(['new', 'active', 'closed'], $this->column('select name from states order by id'));
    }

    public function testMigrationsThatFailAreSaidToHaveFailed(): void
    {
        $this->expectExceptionMessageMatches('#/specrig\.db did not migrate: .*no such table: missing#');
        $this->migrated('insert into missing values (1)');
    }

    public function testTheEndOfTheRunRemovesTheFile(): void
    {
        $file = $this->migrated(self::SEEDED);
        $path = Address::parse($file->address)->database;

        Rig::endRun();

        $this->assertDirectoryDoesNotExist(dirname($path));
    }
}
