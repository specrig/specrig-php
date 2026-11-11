<?php

declare(strict_types=1);

namespace Specrig\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Specrig\Mysql\Server;
use Specrig\Rig;
use Specrig\Sql\Address;

final class MysqlTest extends TestCase
{
    private const IMAGE = 'mysql:8.4';

    private const SEEDED = [
        'create table states (id int auto_increment primary key, name varchar(50) not null)',
        "insert into states (name) values ('new'), ('active'), ('closed')",
        'create table categories (id bigint auto_increment primary key, parent_id bigint, name varchar(50) not null,
            name_length int generated always as (char_length(name)) stored, created_at datetime(6) not null default current_timestamp(6),
            foreign key (parent_id) references categories(id))',
        "insert into categories (parent_id, name) values (null, 'Master')",
        "insert into categories (parent_id, name) values (1, 'Child')",
        'create table vendors (id int auto_increment primary key, state_id int not null, name varchar(50) not null, foreign key (state_id) references states(id))',
        "insert into vendors (state_id, name) values (1, 'R1')",
        'create table audit (id int auto_increment primary key, what varchar(50) not null)',
        'create trigger vendors_audited after insert on vendors for each row insert into audit (what) values (new.name)',
        "set session sql_mode = concat(@@sql_mode, ',PIPES_AS_CONCAT')",
        "create trigger vendors_forgotten after delete on vendors for each row insert into audit (what) values ('-' || old.name)",
        'set session sql_mode = default',
        'create table orders (id int auto_increment primary key, vendor_id int not null, foreign key (vendor_id) references vendors(id))',
    ];

    private \PDO $database;

    protected function tearDown(): void
    {
        Rig::endRun();
    }

    /**
     * @param list<string> $schema
     * @param list<string> $keep
     */
    private function migrated(array $schema, array $keep = []): Server
    {
        $server = Server::start('specrig', null, self::IMAGE);
        $this->database = Address::parse($server->address)->pdo();
        $server->once(function () use ($schema) {
            foreach ($schema as $statement) {
                $this->database->exec($statement);
            }
        }, $keep);
        return $server;
    }

    /** @return list<mixed> */
    private function column(string $query): array
    {
        return $this->database->query($query)->fetchAll(\PDO::FETCH_COLUMN);
    }

    private function inserted(string $statement): int
    {
        $this->database->exec($statement);
        return (int) $this->database->lastInsertId();
    }

    private function admin(Server $server): \PDO
    {
        return Address::parse($server->address)->with(database: '', query: [])->pdo();
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

        $this->assertSame(4, $this->inserted("insert into states (name) values ('next')"));
        $this->assertSame(2, $this->inserted("insert into vendors (state_id, name) values (1, 'R2')"));
        $this->assertSame(1, $this->inserted('insert into orders (vendor_id) values (1)'));
    }

    public function testACounterMovedByARowSinceDeletedIsRewound(): void
    {
        $server = $this->migrated(self::SEEDED);
        $this->database->exec("insert into states (name) values ('extra')");
        $this->database->exec("delete from states where name = 'extra'");

        $server->reset();

        $this->assertSame(4, $this->inserted("insert into states (name) values ('next')"));
    }

    public function testAGapTheMigrationsLeftIsKept(): void
    {
        $server = $this->migrated([
            'create table states (id int auto_increment primary key, name varchar(50) not null)',
            "insert into states (name) values ('new'), ('active'), ('dropped')",
            "delete from states where name = 'dropped'",
        ]);
        $this->database->exec("insert into states (name) values ('extra')");

        $server->reset();

        $this->assertSame(4, $this->inserted("insert into states (name) values ('next')"));
    }

    public function testAnOrderComesBackBeforeItsVendorAndATreeWithItsRoot(): void
    {
        $server = $this->migrated([...self::SEEDED, 'insert into orders (vendor_id) values (1)']);
        $this->database->exec('delete from orders');
        $this->database->exec("update vendors set name = 'renamed'");
        $this->database->exec('delete from categories where parent_id is not null');
        $this->database->exec("update states set name = 'renamed' where id = 1");

        $server->reset();

        $this->assertSame([1], $this->column('select vendor_id from orders'));
        $this->assertSame(['R1'], $this->column('select name from vendors'));
        $this->assertSame([null, 1], $this->column('select parent_id from categories order by id'));
    }

    public function testPuttingRowsBackFiresNoTriggerAndKeepsThem(): void
    {
        $server = $this->migrated(self::SEEDED);
        $this->database->exec("update vendors set name = 'renamed'");

        $server->reset();

        $this->assertSame([], $this->column('select what from audit'));
        $this->database->exec("insert into vendors (state_id, name) values (1, 'R2')");
        $this->database->exec("delete from vendors where name = 'R2'");
        $this->assertSame(['R2', '-R2'], $this->column('select what from audit order by id'));
    }

    public function testATableNoCaseChangedIsLeftAsItIs(): void
    {
        $server = $this->migrated(self::SEEDED);
        $this->database->exec("create trigger vendors_held before delete on vendors for each row signal sqlstate '45000' set message_text = 'vendors emptied'");
        $this->database->exec("insert into states (name) values ('extra')");

        $server->reset();

        $this->assertSame(['R1'], $this->column('select name from vendors'));
    }

    /** @return iterable<string, array{list<string>}> */
    public static function cases(): iterable
    {
        yield 'on a table no case changed' => [[]];
        yield 'on a table a case changed' => [["update vendors set name = 'renamed'"]];
    }

    /** @param list<string> $case */
    #[DataProvider('cases')]
    public function testATriggerAnEarlierResetLostComesBack(array $case): void
    {
        $server = $this->migrated(self::SEEDED);
        $this->database->exec('drop trigger vendors_audited');
        foreach ($case as $statement) {
            $this->database->exec($statement);
        }

        $server->reset();

        $this->database->exec("insert into vendors (state_id, name) values (1, 'R2')");
        $this->assertSame(['R2'], $this->column('select what from audit'));
    }

    public function testTriggersComeBackInTheirOrder(): void
    {
        $server = $this->migrated([
            'create table items (id int auto_increment primary key, name varchar(50) not null)',
            'create table log (id int auto_increment primary key, what varchar(50) not null)',
            "create trigger b_first after insert on items for each row insert into log (what) values ('first')",
            "create trigger a_second after insert on items for each row follows b_first insert into log (what) values ('second')",
            "insert into items (name) values ('seeded')",
        ]);
        $this->database->exec("insert into items (name) values ('extra')");

        $server->reset();

        $this->database->exec("insert into items (name) values ('next')");
        $this->assertSame(['first', 'second', 'first', 'second'], $this->column('select what from log order by id'));
    }

    public function testARowTheMigrationsMovedToIdZeroKeepsIt(): void
    {
        $server = $this->migrated([
            'create table states (id int auto_increment primary key, name varchar(50) not null)',
            "insert into states (name) values ('none'), ('new')",
            "update states set id = 0 where name = 'none'",
        ]);
        $this->database->exec("insert into states (name) values ('extra')");

        $server->reset();

        $this->assertSame(['0 none', '2 new'], $this->column("select concat(id, ' ', name) from states order by id"));
    }

    public function testColumnsTheServerFillsComeBackAsTheyWere(): void
    {
        $server = $this->migrated(self::SEEDED);
        $created = $this->column('select created_at from categories order by id');
        $this->database->exec("update categories set name = 'Renamed' where id = 1");

        $server->reset();

        $this->assertSame([6, 5], $this->column('select name_length from categories order by id'));
        $this->assertSame($created, $this->column('select created_at from categories order by id'));
    }

    public function testMigrationsThatLeaveNoRowsLeaveNothingAside(): void
    {
        $server = $this->migrated(['create table orders (id int auto_increment primary key)']);
        $this->database->exec('insert into orders values ()');

        $server->reset();

        $this->assertSame(1, $this->inserted('insert into orders values ()'));
        $this->assertSame([], $this->column("select table_name from information_schema.tables where table_schema = database() and table_name like 'specrig\\_kept%'"));
    }

    public function testAKeptTableIsLeftAsTheCaseLeftIt(): void
    {
        $server = $this->migrated(self::SEEDED, ['states']);
        $this->database->exec("insert into states (name) values ('extra'), ('gone')");
        $this->database->exec("delete from states where name = 'gone'");

        $server->reset();

        $this->assertSame(['new', 'active', 'closed', 'extra'], $this->column('select name from states order by id'));
        $this->assertSame(6, $this->inserted("insert into states (name) values ('next')"));
    }

    public function testMigrationsThatFailAreSaidToHaveFailed(): void
    {
        $this->expectExceptionMessageMatches('/^specrig_test_\d+ did not migrate: .*doesn\'t exist/');
        $this->migrated(['insert into nothing values (1)']);
    }

    public function testADatabaseAKilledRunLeftOverAnHourAgoIsDroppedByTheNextRun(): void
    {
        $admin = $this->admin($this->migrated([]));
        foreach (['specrig_test_1' => time() - 7200, 'specrig_test_2' => time()] as $name => $made) {
            $admin->exec("drop database if exists {$name}");
            $admin->exec("create database {$name}");
            $admin->exec("create table {$name}.specrig_mark (id int) comment 'specrig {$made}'");
        }

        Server::start('specrig', null, self::IMAGE);

        $left = $admin->query("select schema_name from information_schema.schemata where schema_name in ('specrig_test_1', 'specrig_test_2')")->fetchAll(\PDO::FETCH_COLUMN);
        $admin->exec('drop database if exists specrig_test_2');
        $this->assertSame(['specrig_test_2'], $left);
    }

    public function testTheEndOfTheRunDropsTheDatabaseATransactionLeftOpenHolds(): void
    {
        $server = $this->migrated(self::SEEDED);
        $admin = $this->admin($server);
        $this->database->beginTransaction();
        $this->database->exec("insert into states (name) values ('held')");

        Rig::endRun();

        $database = Address::parse($server->address)->database;
        $this->assertSame([], $admin->query("select schema_name from information_schema.schemata where schema_name = '{$database}'")->fetchAll(\PDO::FETCH_COLUMN));
    }
}
