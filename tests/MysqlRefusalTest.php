<?php

declare(strict_types=1);

namespace Specrig\Tests;

use Specrig\Database;
use Specrig\Mysql;

final class MysqlRefusalTest extends Refusals
{
    private const LONGEST = 'tttttttttttttttttttttttttttttttttttttttttttttttttttttttttttttttt';

    protected static function schema(): array
    {
        return [
            'create table states (id int auto_increment primary key, name varchar(50) not null)',
            "insert into states (name) values ('new'), ('active'), ('closed')",
            'create table vendors (id int auto_increment primary key, state_id int not null, name varchar(50) not null, foreign key (state_id) references states(id))',
            "insert into vendors (state_id, name) values (1, 'R1')",
            'create table ' . self::LONGEST . ' (id int auto_increment primary key)',
        ];
    }

    protected static function database(): Database
    {
        return new Mysql('DATABASE_URL');
    }

    protected static function leftovers(): string
    {
        return "select trigger_name from information_schema.triggers where trigger_schema = database() and trigger_name like 'specrig%'
                union all select table_name from information_schema.tables where table_schema = database() and table_name = 'specrig_refusal'";
    }

    public function testATableWithTheLongestNameCanBeRefused(): void
    {
        $this->database->refuse(self::LONGEST);

        $this->refused($this->connection, 'insert into ' . self::LONGEST . ' values ()', 'insert on ' . self::LONGEST);
    }
}
