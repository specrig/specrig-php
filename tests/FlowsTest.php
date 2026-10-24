<?php

declare(strict_types=1);

namespace Specrig\Tests;

use PHPUnit\Framework\TestCase;
use Specrig\Flows;
use Specrig\Flows\Branch;
use Specrig\Flows\Doc;
use Specrig\Flows\Input;
use Specrig\Json;
use Specrig\Record;
use Specrig\Run;

final class FlowsTest extends TestCase
{
    private static function in(int $seq, string $name, ?string $body = null, ?string $error = null, string $step = 'Action'): Record
    {
        return new Record($seq, 'in', 'http', $name, $step, $body === null ? null : Json::decode($body), $error);
    }

    private static function out(int $seq, string $name, ?string $body = null, string $step = 'Action'): Record
    {
        return new Record($seq, 'out', 'http', $name, $step, $body === null ? null : Json::decode($body));
    }

    /** @param list<Input> $inputs */
    private static function doc(array $inputs): Doc
    {
        $doc = new Doc('shop');
        $doc->inputs = $inputs;
        return $doc;
    }

    private static function input(string $message, ?string $description = null): Input
    {
        $input = new Input($message);
        $input->description = $description;
        $branch = new Branch();
        $branch->cases = ['shop.feature: The visitor sees the shop'];
        $input->branches = [$branch];
        return $input;
    }

    public function testADescriptionWhoseInputIsGoneIsOrphanedAndComesBackWithItsInput(): void
    {
        $gone = Flows::reconcile(self::doc([self::input('http GET /shop', 'Витрина')]), self::doc([]));
        $this->assertCount(1, $gone->orphaned);
        $this->assertSame(['http GET /shop', 'Витрина'], [$gone->orphaned[0]->anchor, $gone->orphaned[0]->description]);

        $back = Flows::reconcile($gone, self::doc([self::input('http GET /shop')]));
        $this->assertSame('Витрина', $back->inputs[0]->description);
        $this->assertNull($back->orphaned);
    }

    public function testTheRecordSaysFirstThatItIsSpecrigs(): void
    {
        $this->assertSame(['specrig: flows/1', 'service: shop'], array_slice(explode("\n", Flows::encode(Flows::build('shop', []))), 2, 2));
    }

    public function testCasesThatSentTheSameShareABranchAndARefusalIsABranchOfItsOwn(): void
    {
        $doc = Flows::build('shop', [
            new Run('orders.feature: one', [self::in(1, 'GET /orders/{id}'), self::out(2, 'GET /invoices/{orderId}')]),
            new Run('orders.feature: two', [self::in(1, 'GET /orders/{id}'), self::out(2, 'GET /invoices/{orderId}')]),
            new Run('orders.feature: three', [self::in(1, 'GET /orders/{id}', error: 'HTTP 404 order_not_found')]),
        ]);

        $this->assertCount(1, $doc->inputs);
        $input = $doc->inputs[0];
        $this->assertSame('http GET /orders/{id}', $input->message);
        [$refused, $answered] = $input->branches;
        $this->assertSame('HTTP 404 order_not_found', $refused->refused);
        $this->assertSame(['orders.feature: three'], $refused->cases);
        $this->assertSame(['http GET /invoices/{orderId}'], $answered->sends);
        $this->assertSame(['orders.feature: one', 'orders.feature: two'], $answered->cases);
    }

    public function testABlockSentAgainStraightAfterItselfIsOnePassOfALoop(): void
    {
        $doc = Flows::build('shop', [
            new Run('a.feature: loop', [
                self::in(1, 'POST /orders'),
                self::out(2, 'GET /a'), self::out(3, 'GET /b'), self::out(4, 'GET /a'), self::out(5, 'GET /b'), self::out(6, 'GET /c'),
            ]),
        ]);

        $this->assertSame(['http GET /a', 'http GET /b', 'http GET /c'], $doc->inputs[0]->branches[0]->sends);
    }

    public function testANeedIsAFieldThatSplitsTheAnsweredBranchesExactly(): void
    {
        $doc = Flows::build('shop', [
            new Run('pay.feature: saved card', [self::in(1, 'POST /orders', '{"card": {"id": "x"}, "items": [{"sku": "a"}]}'), self::out(2, 'POST /payments')]),
            new Run('pay.feature: invoice', [self::in(1, 'POST /orders', '{"items": [{"sku": "a"}]}')]),
        ]);

        $this->assertSame(['http POST /payments' => ['card.id']], $doc->inputs[0]->needs);
        $this->assertSame(['card.id', 'items[].sku'], $doc->inputs[0]->fields);
    }

    public function testOnlyWhatAWhenReachesIsAnInput(): void
    {
        $doc = Flows::build('shop', [
            new Run('a.feature: one', [
                self::in(1, 'POST /setup', step: 'Context'), self::out(2, 'GET /x', step: 'Context'),
                self::in(3, 'GET /orders/{id}'), self::out(4, 'GET /y'),
            ]),
        ]);

        $this->assertCount(1, $doc->inputs);
        $this->assertSame('http GET /orders/{id}', $doc->inputs[0]->message);
        $this->assertSame(['http GET /y'], $doc->inputs[0]->branches[0]->sends);
        $this->assertSame(['http GET /x', 'http GET /y'], array_map(fn ($o) => $o->message, $doc->outputs));
    }

    public function testARecordReadsBackAsItWasWritten(): void
    {
        $doc = Flows::build('shop', [
            new Run('orders.feature: one', [self::in(1, 'GET /orders/{id}', '{"page": ["2"]}'), self::out(2, 'POST /reminders', '{"order": 7}')]),
        ]);
        $doc->inputs[0]->description = "Заказ: что осталось оплатить\n\nВторой абзац";
        $doc->inputs[0]->branches[0]->description = 'true';
        $file = tempnam(sys_get_temp_dir(), 'flows');

        file_put_contents($file, Flows::encode($doc));
        $read = Flows::read($file);

        $this->assertSame(Flows::encode($doc), Flows::encode($read));
        $this->assertSame("Заказ: что осталось оплатить\n\nВторой абзац", $read->inputs[0]->description);
        $this->assertSame('true', $read->inputs[0]->branches[0]->description);
        $this->assertSame(['order'], $read->inputs[0]->branches[0]->sent['http POST /reminders']);
    }
}
