<?php

declare(strict_types=1);

namespace Specrig\Tests;

use Specrig\Database;
use Specrig\Postgres;
use Specrig\Write;

final class RefusalTest extends Refusals
{
    protected static function schema(): array
    {
        return [<<<'SQL'
            create table states (id int generated always as identity primary key, name text not null);
            insert into states (name) values ('new'), ('active'), ('closed');
            create table vendors (id serial primary key, state_id int not null references states(id), name text not null);
            insert into vendors (state_id, name) values (1, 'R1');
            SQL];
    }

    protected static function database(): Database
    {
        return new Postgres('DATABASE_URL');
    }

    protected static function leftovers(): string
    {
        return "select schema_name from information_schema.schemata where schema_name = 'specrig_refusal'";
    }

    private function postgres(): Postgres
    {
        assert($this->database instanceof Postgres);
        return $this->database;
    }

    public function testAWriteThatReturnsRowsIsRefusedToo(): void
    {
        $this->database->refuse('vendors');

        $this->refused($this->connection, "insert into vendors (state_id, name) values (1, 'R2') returning id", 'insert on vendors');
    }

    public function testATableIsNamedWithItsSchema(): void
    {
        $this->database->refuse('public.vendors', Write::Delete);

        $this->refused($this->connection, 'delete from vendors', 'delete on vendors');
    }

    public function testACommitRefusalLetsTheStatementsThroughAndFailsTheCommit(): void
    {
        $this->postgres()->refuseCommit('states');
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
        $this->postgres()->refuseCommit('states');

        $this->connection->beginTransaction();
        $this->connection->query('select name from states')->fetchAll();
        $this->connection->exec("insert into vendors (state_id, name) values (1, 'R2')");
        $this->connection->commit();

        $this->assertSame(['R1', 'R2'], $this->column('select name from vendors order by id'));
    }
}
