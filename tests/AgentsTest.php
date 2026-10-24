<?php

declare(strict_types=1);

namespace Specrig\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Specrig\Tool\Agents;
use Specrig\Tool\Area;
use Specrig\Tool\Modes;
use Symfony\Component\Yaml\Yaml;

final class AgentsTest extends TestCase
{
    /** @return array<string, string> */
    private static function filled(): array
    {
        return [
            'what' => 'service', 'writer' => '`php vendor/bin/specrig agents`', 'version' => '`specrig/specrig` 0.1.0',
            'cut' => '`tests/internal/Harness.php`', 'validate' => '`php vendor/bin/behat -c tests/behat.php`',
            'boundary' => "What the harness stands in for is the platform's.", 'spec-paths' => '`tests/features/`',
            'platform-paths' => '`tests/internal/`', 'tasks' => '', 'modes' => Agents::modeRows(),
            'platform-files' => '`tests/internal/`', 'generated' => '', 'tool' => 'specrig', 'config' => '`specrig.yaml`',
            'areas' => '| flows | `tests/flows.yaml` | spec, redgreen, platform | — |',
            'cases' => '`.feature` files in `tests/features/`', 'contracts' => '', 'describes' => '', 'binding' => 'Bind at the edge.',
        ];
    }

    public function testEveryPartRendersWithEverySlotFilled(): void
    {
        foreach (['header', 'contributing', 'short', 'rule11', 'reference'] as $part) {
            $text = Agents::render($part, self::filled());
            $this->assertStringNotContainsString('{{', $text, $part);
            $this->assertStringNotContainsString("\n\n\n", $text, $part);
        }
    }

    public function testASlotLeftOutIsNamed(): void
    {
        $slots = self::filled();
        unset($slots['binding']);

        $this->expectExceptionMessage('rule11 leaves binding unfilled');
        Agents::render('rule11', $slots);
    }

    public function testASlotNoPartHasIsNamed(): void
    {
        $this->expectExceptionMessage('no part has the slots harness');
        Agents::render('rule11', [...self::filled(), 'harness' => 'x']);
    }

    public function testAnEmptySlotAloneOnItsLineTakesTheLine(): void
    {
        $this->assertStringContainsString(
            "contradictory | a test where the defect is, then the fix. None of the cycle below |\n| a **spike**",
            Agents::render('rule11', self::filled()),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function fixtures(): iterable
    {
        foreach (glob(__DIR__ . '/fixtures/protocol/agents/*.yaml') as $file) {
            yield basename($file) => [$file];
        }
    }

    #[DataProvider('fixtures')]
    public function testAgentsMdIsJudgedAsTheProtocolsFixturesJudgeIt(string $file): void
    {
        $fixture = Yaml::parseFile($file);
        $this->assertSame('agents-fixtures/1', $fixture['specrig']);
        foreach ($fixture['cases'] as $case) {
            $this->assertSame($case['verdict'], Agents::verdict($case['agents']), $case['name']);
        }
    }

    public function testTheModeTableNamesTheCases(): void
    {
        $rows = Agents::modeRows();

        $this->assertStringContainsString('| spec | `tests/features/**/*.feature` |', $rows);
        $this->assertSame(count(Modes::all()) - 1, substr_count($rows, "\n"));
    }

    public function testTheAreaTableSaysHowEachAreaOpens(): void
    {
        $this->assertSame(
            "| composer | `composer.json` | platform | redgreen |\n| steps | `tests/steps/` | as the table says | platform |\n| vendored | `lib/vendored/` | no mode | — |",
            Agents::areaRows([
                new Area('composer', ['composer.json'], ['platform'], ['redgreen']),
                new Area('steps', ['tests/steps/'], null, ['platform']),
                new Area('vendored', ['lib/vendored/'], []),
            ]),
        );
    }

    public function testAnAreaAnotherNamesAlikeAndDecidesIsLeftOutOfTheTable(): void
    {
        $this->assertSame("| api | `./api` | platform | — |", Agents::areaRows([
            new Area('contracts', ['api/'], ['spec']),
            new Area('api', ['./api'], ['platform']),
        ]));
    }
}
