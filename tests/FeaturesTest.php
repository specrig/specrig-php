<?php

declare(strict_types=1);

namespace Specrig\Tests;

use PHPUnit\Framework\TestCase;
use Specrig\Features;
use Specrig\Scenario;

final class FeaturesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = Temp::dir();
    }

    private function write(string $file, string $body): Features
    {
        @mkdir(dirname("{$this->dir}/{$file}"), 0777, true);
        file_put_contents("{$this->dir}/{$file}", $body);
        return new Features($this->dir);
    }

    public function testACaseIsNamedByItsFileBelowTheDirectoryAndItsTitle(): void
    {
        $features = $this->write('orders/pay.feature', <<<'GHERKIN'
            Feature: Paying

              # The reason, in the present tense.
              Scenario: The customer pays
                When the customer pays

              Scenario Outline: The customer pays in <currency>
                When the customer pays in <currency>
                Examples:
                  | currency |
                  | EUR      |
            GHERKIN);

        $this->assertSame(
            ['orders/pay.feature: The customer pays', 'orders/pay.feature: The customer pays in <currency>'],
            array_map(fn (Scenario $c) => $c->label(), $features->cases()),
        );
    }

    public function testACaseTaggedIgnoreOrInAnIgnoredFeatureIsKnownAsIgnored(): void
    {
        $features = $this->write('a.feature', <<<'GHERKIN'
            Feature: A

              @ignore @slow
              Scenario: Skipped
                When it runs

              Scenario: Run
                When it runs
            GHERKIN);

        $this->assertSame([['Skipped', true], ['Run', false]], array_map(fn (Scenario $c) => [$c->title, $c->ignored], $features->cases()));
    }
}
