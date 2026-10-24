<?php

declare(strict_types=1);

namespace Specrig\Tests;

use PHPUnit\Framework\TestCase;
use Specrig\Backend;
use Specrig\Contract;
use Specrig\Http\Request;
use Specrig\Http\Unreachable;
use Specrig\Json;
use Specrig\StandIns;
use Specrig\Trace;

final class BackendTest extends TestCase
{
    private Trace $trace;

    private Backend $shop;

    private StandIns $standIns;

    protected function setUp(): void
    {
        $this->trace = new Trace();
        $this->shop = new Backend('https://shop.test');
        $this->shop->bind('shop', $this->trace, new Contract(__DIR__ . '/fixtures/shop.yaml', 'shop', 'Error'));
        $this->standIns = new StandIns([$this->shop]);
    }

    private function get(string $url): mixed
    {
        return $this->standIns->send(new Request('GET', $url))->json();
    }

    public function testTheReplyNamingTheMostOfTheQueryWins(): void
    {
        $this->shop->reply('GET', '/items/1', 200, ['id' => 'any']);
        $this->shop->replyTo('GET', '/items/1', ['view' => 'full'], 200, ['id' => 'full']);

        $this->assertSame('full', $this->get('https://shop.test/items/1?view=full&lang=en')['id']);
        $this->assertSame('any', $this->get('https://shop.test/items/1?lang=en')['id']);
    }

    public function testOfTwoRepliesNamingAsMuchOfTheQueryTheLaterWins(): void
    {
        $this->shop->replyTo('GET', '/items/1', ['view' => 'full'], 200, ['id' => 'view']);
        $this->shop->replyTo('GET', '/items/1', ['lang' => 'en'], 200, ['id' => 'lang']);

        $this->assertSame('lang', $this->get('https://shop.test/items/1?view=full&lang=en')['id']);
    }

    public function testACallNoStepAnsweredGetsA501AndIsNamed(): void
    {
        $answer = $this->standIns->send(new Request('GET', 'https://shop.test/items/1?view=full'));

        $this->assertSame(501, $answer->status);
        $this->assertSame(['GET /items/1?view=full'], $this->shop->unanswered());
    }

    public function testARequestATimeOutTakesIsNotAnsweredInTimeAndIsStillTraced(): void
    {
        $this->shop->reply('GET', '/items/1', 200, ['id' => '1']);
        $this->shop->timeOut('GET', '/items/1', ['view' => 'full']);

        $this->assertSame('1', $this->get('https://shop.test/items/1')['id']);
        try {
            $this->get('https://shop.test/items/1?view=full');
            $this->fail('the request was answered');
        } catch (Unreachable $e) {
            $this->assertTrue($e->timedOut);
        }
        $this->assertCount(2, $this->trace->records());
    }

    public function testWhatTheContractDoesNotAllowIsKept(): void
    {
        $this->shop->reply('POST', '/items/new', 204);

        $this->standIns->send(new Request('POST', 'https://shop.test/items/new', ['Content-Type' => 'application/json'], Json::encode(['count' => 1])));

        $this->assertCount(1, $this->shop->violations());
    }

    public function testARequestIsTracedByTheRouteOfTheContractThatTakesIt(): void
    {
        $this->shop->reply('GET', '/items/7', 200, ['id' => '7']);

        $this->get('https://shop.test/items/7');

        $this->assertCount(1, $this->trace->records());
        $sent = $this->trace->records()[0];
        $this->assertSame(['out', 'http', 'GET /items/{id}'], [$sent->dir, $sent->kind, $sent->name]);
    }

    public function testARequestToAnOriginNoStandInTakesFailsAndIsKept(): void
    {
        try {
            $this->get('https://maps.example.com/tiles');
            $this->fail('the request was answered');
        } catch (Unreachable) {
        }

        $this->assertSame(['GET https://maps.example.com/tiles'], $this->standIns->outside());
    }

    public function testWhatReachedTheNeighbourIsReadBackWithItsQueryAndBody(): void
    {
        $this->shop->reply('POST', '/items/new', 204);

        $this->standIns->send(new Request('POST', 'https://shop.test/items/new?draft=1&tag=a&tag=b', [], Json::encode(['name' => 'box'])));

        $received = $this->shop->requests('POST', '/items/new');
        $this->assertCount(1, $received);
        $this->assertSame(['draft' => ['1'], 'tag' => ['a', 'b']], $received[0]->query);
        $this->assertSame(['name' => 'box'], $received[0]->body());
    }
}
