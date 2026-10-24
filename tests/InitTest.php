<?php

declare(strict_types=1);

namespace Specrig\Tests;

use PHPUnit\Framework\TestCase;
use Specrig\Tool\Agents;
use Specrig\Tool\Cli;
use Specrig\Tool\Init;

final class InitTest extends TestCase
{
    private function copy(string $fixture): string
    {
        $dir = Temp::dir() . '/' . $fixture;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . "/fixtures/{$fixture}", \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $target = $dir . substr($file->getPathname(), strlen(__DIR__ . "/fixtures/{$fixture}"));
            @mkdir(dirname($target), 0777, true);
            copy($file->getPathname(), $target);
        }
        return $dir;
    }

    public function testInitLaysTheHarnessOutFromWhatTheCodeReads(): void
    {
        $dir = $this->copy('laravel');

        [$code, $out] = Init::run([$dir, '--backend', 'crm=CRM_URL', '--contract', 'crm=../docs/crm.yaml', '--service', 'gateway'], '/');

        $this->assertSame(0, $code, $out);
        $harness = (string) file_get_contents("{$dir}/tests/internal/Harness.php");
        $this->assertStringContainsString("public readonly Backend \$crm = new Backend('https://crm.gateway.test', setting: 'CRM_URL', contract: '../docs/crm.yaml'),", $harness);
        $this->assertStringContainsString("public readonly Postgres \$database = new Postgres('DB_URL'),", $harness);
        $this->assertStringContainsString("public readonly Redis \$cache = new Redis('REDIS_URL'),", $harness);
        $this->assertStringContainsString("public readonly Bus \$bus = new Bus('RABBIT_URL'),", $harness);
        $this->assertStringContainsString("'DB_CONNECTION' => 'pgsql'", $harness);
        $this->assertStringContainsString('return [\App\Console\Commands\ConsumeLetters::class];', $harness);
        $this->assertSame("guard: [php, vendor/bin/specrig]\n", file_get_contents("{$dir}/specrig.yaml"));
        $this->assertSame('AGENTS.md', readlink("{$dir}/CLAUDE.md"));
        $this->assertFileExists("{$dir}/tests/surface.yaml");
        $this->assertFileExists("{$dir}/specrig.md");
        $this->assertFileDoesNotExist("{$dir}/Makefile");
        $this->assertStringContainsString('"specrig/laravel":', (string) file_get_contents("{$dir}/composer.json"));
    }

    public function testInitMapsTheHarnessAndTheStepsToTheirLowercaseDirectories(): void
    {
        $dir = $this->copy('laravel');

        Init::run([$dir, '--backend', 'crm=CRM_URL'], '/');

        $this->assertSame(
            ['Tests\\' => 'tests/', 'Tests\\Internal\\' => 'tests/internal/', 'Tests\\Steps\\' => 'tests/steps/'],
            json_decode((string) file_get_contents("{$dir}/composer.json"), true)['autoload-dev']['psr-4'],
        );
    }

    private const REFERENCE ="Read @specrig.md before the first edit: Rule #11, and how this service's suite runs.";

    public function testInitWritesAnAgentsMdOfTheServicesOwnThatBringsSpecrigMdIn(): void
    {
        $dir = $this->copy('laravel');

        [, $out] = Init::run([$dir, '--backend', 'crm=CRM_URL', '--service', 'gateway'], '/');

        $stub = (string) file_get_contents("{$dir}/AGENTS.md");
        $this->assertStringStartsWith("# AGENTS.md\n\nInstructions for AI coding agents working in gateway.\n\n" . self::REFERENCE . "\n", $stub);
        $this->assertStringContainsString("\n## This service\n", $stub);
        $this->assertSame('ok', Agents::verdict($stub));
        $this->assertStringContainsString("+ AGENTS.md\n+ specrig.md", $out);
    }

    public function testInitAddsTheLineThatBringsSpecrigMdInToTheEndOfAnAgentsMdThatLacksIt(): void
    {
        $dir = $this->copy('laravel');
        file_put_contents("{$dir}/AGENTS.md", 'ours');

        [, $out] = Init::run([$dir, '--backend', 'crm=CRM_URL'], '/');

        $this->assertSame("ours\n\n" . self::REFERENCE . "\n", file_get_contents("{$dir}/AGENTS.md"));
        $this->assertStringContainsString('~ AGENTS.md: the line that brings specrig.md in, at its end', $out);
    }

    public function testInitSaysWhatIsLeftOfAnAgentsMdThatStillCarriesRule11(): void
    {
        $dir = $this->copy('laravel');
        file_put_contents("{$dir}/AGENTS.md", "# AGENTS.md\n\n## Rule #11 — specification before code\n");

        [, $out] = Init::run([$dir, '--backend', 'crm=CRM_URL'], '/');

        $this->assertStringContainsString("it still carries Rule #11, which specrig.md holds now: keep the service's own sections in it and delete the rest", $out);
        $this->assertSame([1, "= specrig.md\nError: AGENTS.md still carries Rule #11, which specrig.md holds now: keep the service's own sections in it and delete the rest"], Cli::agents([$dir], '/'));
    }

    public function testSpecrigMdHoldsNothingInitWasToldSoAgentsWritesItAsInitDid(): void
    {
        $dir = $this->copy('laravel');

        Init::run([$dir, '--backend', 'crm=CRM_URL', '--contract', 'crm=../docs/crm.yaml', '--service', 'gateway'], '/');

        $written = (string) file_get_contents("{$dir}/specrig.md");
        $this->assertStringNotContainsString('crm', $written);
        $this->assertStringNotContainsString('gateway', $written);
        $this->assertStringNotContainsString('`make ', $written);
        $this->assertStringStartsWith("# specrig.md\n\n", $written);
        $this->assertSame([0, '= specrig.md'], Cli::agents([$dir], '/'));
        $this->assertSame($written, file_get_contents("{$dir}/specrig.md"));
    }

    public function testAgentsFailsWhileAgentsMdDoesNotBringSpecrigMdInAndWritesSpecrigMdAllTheSame(): void
    {
        $dir = $this->copy('laravel');
        file_put_contents("{$dir}/AGENTS.md", "ours\n");

        [$code, $out] = Cli::agents([$dir], '/');

        $this->assertSame(1, $code);
        $this->assertStringStartsWith("+ specrig.md\nError: AGENTS.md does not bring specrig.md in: add the line\n\n" . self::REFERENCE . "\n\n", $out);
        $this->assertFileExists("{$dir}/specrig.md");
    }

    public function testAgentsWritesNothingWhereThereIsNoAgentsMd(): void
    {
        $dir = $this->copy('laravel');

        try {
            Cli::agents([$dir], '/');
            $this->fail('agents wrote where there is no AGENTS.md');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringStartsWith('there is no AGENTS.md here', $e->getMessage());
        }
        $this->assertFileDoesNotExist("{$dir}/specrig.md");
    }

    public function testInitWritesNoFileThatExists(): void
    {
        $dir = $this->copy('laravel');
        mkdir("{$dir}/tests/internal", 0777, true);
        file_put_contents("{$dir}/tests/internal/Harness.php", 'mine');

        [, $out] = Init::run([$dir, '--backend', 'crm=CRM_URL'], '/');

        $this->assertStringContainsString('= tests/internal/Harness.php — kept', $out);
        $this->assertSame('mine', file_get_contents("{$dir}/tests/internal/Harness.php"));
    }

    public function testInitRefusesASettingTheCodeDoesNotRead(): void
    {
        $this->expectExceptionMessage('gateway reads no setting CRM_ADDRESS; it reads CRM_TIMEOUT, CRM_URL, RABBIT_URL');
        Init::run([$this->copy('laravel'), '--backend', 'crm=CRM_ADDRESS', '--service', 'gateway'], '/');
    }

    public function testInitRefusesAServiceWhoseNeighbourGoesUnnamed(): void
    {
        $this->expectExceptionMessage('name the neighbour with --backend <name>[=<setting>]');
        Init::run([$this->copy('laravel')], '/');
    }

    public function testASymfonySettingNothingAnswersIsAnsweredEmpty(): void
    {
        $dir = $this->copy('symfony');

        Init::run([$dir, '--backend', 'warehouse=WAREHOUSE_URL', '--service', 'stock'], '/');

        $this->assertStringContainsString("return ['RESERVE_LIMIT' => ''];", (string) file_get_contents("{$dir}/tests/internal/Harness.php"));
    }
}
