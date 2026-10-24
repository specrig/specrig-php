<?php

declare(strict_types=1);

namespace Specrig\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Specrig\Flows;
use Specrig\Record;
use Specrig\Run;

final class FormatTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/fixtures/protocol/records';

    /** @return iterable<string, array{string}> */
    public static function names(): iterable
    {
        foreach (glob(self::FIXTURES . '/*.trace.json') as $file) {
            $name = basename($file, '.trace.json');
            yield $name => [$name];
        }
    }

    #[DataProvider('names')]
    public function testATraceComesOutAsTheRecordEveryWriterAgreesOn(string $name): void
    {
        $runs = array_map(fn (object $run) => new Run($run->case, array_map(fn (object $r) => new Record(
            $r->seq, $r->dir, $r->kind, $r->name, $r->step ?? '', $r->request ?? null, $r->error ?? null,
        ), $run->records)), json_decode(file_get_contents(self::FIXTURES . "/{$name}.trace.json")));
        $actual = Flows::encode(Flows::build($name, $runs));

        $expected = self::FIXTURES . "/{$name}.flows.yaml";
        $this->assertFileExists($expected, "{$name}.flows.yaml is missing; the record comes out as\n{$actual}");
        $this->assertSame(file_get_contents($expected), $actual);
    }
}
