<?php

declare(strict_types=1);

namespace Specrig\Tests;

use Specrig\Database;
use Specrig\Sqlite;

final class SqliteRefusalTest extends Refusals
{
    protected static function schema(): array
    {
        return [<<<'SQL'
            create table states (id integer primary key autoincrement, name text not null);
            insert into states (name) values ('new'), ('active'), ('closed');
            create table vendors (id integer primary key, state_id int not null references states(id), name text not null);
            insert into vendors (state_id, name) values (1, 'R1');
            SQL];
    }

    protected static function database(): Database
    {
        return new Sqlite('DATABASE_URL');
    }

    protected static function leftovers(): string
    {
        return "select name from sqlite_schema where name like 'specrig%'";
    }

    public function testAWriteThatReturnsRowsIsRefusedToo(): void
    {
        $this->database->refuse('vendors');

        $this->refused($this->connection, "insert into vendors (state_id, name) values (1, 'R2') returning id", 'insert on vendors');
    }
}
