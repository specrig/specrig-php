<?php

declare(strict_types=1);

namespace Specrig\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Specrig\Tool\Cli;
use Specrig\Tool\Modes;
use Specrig\Tool\Service;
use Symfony\Component\Yaml\Yaml;

final class RulesTest extends TestCase
{
    private function service(string $specrigYaml): Service
    {
        $dir = Temp::dir();
        file_put_contents("{$dir}/specrig.yaml", $specrigYaml);
        return new Service($dir);
    }

    public static function markers(): iterable
    {
        foreach (glob(__DIR__ . '/fixtures/protocol/markers/*.yaml') as $file) {
            yield basename($file) => [$file];
        }
    }

    #[DataProvider('markers')]
    public function testRulesTakeAndRefuseTheMarkersAsTheProtocolsFixturesDo(string $file): void
    {
        $fixture = Yaml::parseFile($file);
        $this->assertSame('markers-fixtures/1', $fixture['specrig']);
        foreach ($fixture['cases'] as $case) {
            try {
                $this->service($case['marker'])->rules();
                $failed = null;
            } catch (\InvalidArgumentException $e) {
                $failed = $e->getMessage();
            }
            if (isset($case['error'])) {
                $this->assertStringContainsString($case['error'], (string) $failed, $case['name']);
            } else {
                $this->assertNull($failed, $case['name']);
            }
        }
    }

    public function testTheRulesAreTheModesTheClassesWithTheLayoutAndTheAreas(): void
    {
        $rules = $this->service("guard: [php, vendor/bin/specrig]\nagents:\n  areas:\n    gen:\n      paths: [app/Gen/]\n      modes: []\n")->rules();

        $this->assertSame('rules/1', $rules['specrig']);
        $this->assertSame(array_map(fn ($m) => $m->name, Modes::all()), array_column($rules['modes'], 'name'));
        $this->assertSame('`tests/features/**/*.feature`', array_column($rules['modes'], 'allows', 'name')['spec']);
        $this->assertSame([
            ['name' => 'humans', 'patterns' => ['CONTRIBUTING.md']],
            ['name' => 'generated', 'patterns' => ['specrig.md', 'tests/surface.yaml', 'tests/checks.yaml']],
            ['name' => 'platform', 'patterns' => ['tests/internal/', 'tests/behat.php', 'specrig.yaml', 'AGENTS.md', 'CLAUDE.md']],
            ['name' => 'cases', 'patterns' => ['tests/features/**/*.feature']],
            ['name' => 'tests', 'patterns' => ['tests/']],
            ['name' => 'production', 'patterns' => []],
        ], $rules['classes']);
        $this->assertSame([
            ['name' => 'composer', 'paths' => ['composer.json', 'composer.lock'], 'modes' => ['platform'], 'grant' => ['redgreen']],
            ['name' => 'flows', 'paths' => ['tests/flows.yaml'], 'modes' => ['spec', 'redgreen', 'platform']],
            ['name' => 'steps', 'paths' => ['tests/steps/'], 'grant' => ['platform']],
            ['name' => 'gen', 'paths' => ['app/Gen/'], 'modes' => []],
        ], $rules['areas']);
    }

    public function testAnAreaClosedInEveryModeIsPrintedAsAnEmptyList(): void
    {
        $printed = json_decode(Cli::rulesOf($this->service("agents:\n  areas:\n    gen:\n      paths: [app/Gen/]\n      modes: []\n")), true);

        $this->assertSame([], end($printed['areas'])['modes']);
        $this->assertArrayNotHasKey('modes', $printed['areas'][2]);
    }

    public function testAnAreaNamingAModeTheTableDoesNotHaveIsRefusedByItsName(): void
    {
        $this->expectExceptionMessage('agents.areas.events: unknown mode "review" (have: spec, redgreen, platform, auto)');

        $this->service("agents:\n  areas:\n    events:\n      paths: [../shared/events/]\n      grant: [review]\n")->rules();
    }

    public function testAnAreaWithNoPathsIsRefused(): void
    {
        $this->expectExceptionMessage('agents.areas.events names no paths');

        $this->service("agents:\n  areas:\n    events:\n      modes: [spec]\n")->rules();
    }
}
