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

    public function testAReplyAddsItsHeadersToTheResponseAndAContentTypeOfItsOwnReplacesJson(): void
    {
        $this->shop->reply('GET', '/items/1', 200, ['id' => '1'], ['X-Page' => '2']);
        $this->shop->reply('GET', '/items/2', 200, ['id' => '2'], ['content-type' => 'application/vnd.shop+json']);
        $this->shop->reply('GET', '/items/3', 200, ['id' => '3']);

        $first = $this->standIns->send(new Request('GET', 'https://shop.test/items/1'));
        $second = $this->standIns->send(new Request('GET', 'https://shop.test/items/2'));
        $third = $this->standIns->send(new Request('GET', 'https://shop.test/items/3'));

        $this->assertSame(['2', 'application/json'], [$first->header('X-Page'), $first->header('Content-Type')]);
        $this->assertSame(['application/vnd.shop+json', 1], [$second->header('Content-Type'), count($second->headers)]);
        $this->assertSame(['application/json', 1], [$third->header('Content-Type'), count($third->headers)]);
    }

    public function testAReplyWithNoBodyKeepsItsHeaders(): void
    {
        $this->shop->reply('POST', '/items/new', 204, null, ['Location' => '/items/9']);

        $answer = $this->standIns->send(new Request('POST', 'https://shop.test/items/new', [], Json::encode(['name' => 'box'])));

        $this->assertSame([204, '/items/9', ''], [$answer->status, $answer->header('Location'), $answer->body]);
        $this->assertNull($answer->header('Content-Type'));
    }

    public function testAReplyToAQueryAddsItsHeadersToo(): void
    {
        $this->shop->replyTo('GET', '/items/1', ['view' => 'full'], 200, ['id' => 'full'], ['X-View' => 'full']);
        $this->shop->reply('GET', '/items/1', 200, ['id' => 'any']);

        $this->assertSame('full', $this->standIns->send(new Request('GET', 'https://shop.test/items/1?view=full'))->header('X-View'));
        $this->assertNull($this->standIns->send(new Request('GET', 'https://shop.test/items/1'))->header('X-View'));
    }

    public function testASegmentInBracesTakesAnySegmentAndOnlyOne(): void
    {
        $this->shop->reply('GET', '/items/{id}', 200, ['id' => 'any']);

        $this->assertSame('any', $this->get('https://shop.test/items/7')['id']);
        $this->assertSame('any', $this->get('https://shop.test/items/8')['id']);
        $this->assertSame(501, $this->standIns->send(new Request('GET', 'https://shop.test/items/7/parts'))->status);
        $this->assertSame(501, $this->standIns->send(new Request('GET', 'https://shop.test/items'))->status);
        $this->assertSame(501, $this->standIns->send(new Request('POST', 'https://shop.test/items/7'))->status);
    }

    public function testTheExactPathBeatsATemplateWhicheverWasDeclaredFirst(): void
    {
        $this->shop->reply('GET', '/items/{id}', 200, ['id' => 'template']);
        $this->shop->reply('GET', '/items/7', 200, ['id' => 'exact']);
        $this->shop->reply('GET', '/items/{other}', 200, ['id' => 'other']);

        $this->assertSame('exact', $this->get('https://shop.test/items/7')['id']);
        $this->assertSame('template', $this->get('https://shop.test/items/8')['id']);
    }

    public function testOfTwoTemplatesTheFirstDeclaredWins(): void
    {
        $this->shop->reply('GET', '/{kind}/7', 200, ['id' => 'kind']);
        $this->shop->reply('GET', '/items/{id}', 200, ['id' => 'item']);

        $this->assertSame('kind', $this->get('https://shop.test/items/7')['id']);
        $this->assertSame('item', $this->get('https://shop.test/items/8')['id']);
    }

    public function testATemplateKeepsTheRepliesToItsQueries(): void
    {
        $this->shop->reply('GET', '/items/{id}', 200, ['id' => 'any']);
        $this->shop->replyTo('GET', '/items/{id}', ['view' => 'full'], 200, ['id' => 'full']);

        $this->assertSame('full', $this->get('https://shop.test/items/7?view=full')['id']);
        $this->assertSame('any', $this->get('https://shop.test/items/7')['id']);
    }

    public function testATemplateTakesATimeOut(): void
    {
        $this->shop->reply('GET', '/items/{id}', 200, ['id' => '1']);
        $this->shop->timeOut('GET', '/items/{id}', ['view' => 'full']);

        $this->assertSame('1', $this->get('https://shop.test/items/7')['id']);
        $this->expectException(Unreachable::class);
        $this->get('https://shop.test/items/7?view=full');
    }

    public function testRequestsAreReadBackByTheTemplateThatTakesThem(): void
    {
        $this->shop->reply('GET', '/items/{id}', 200, ['id' => '1']);
        $this->get('https://shop.test/items/7');
        $this->get('https://shop.test/items/8');
        $this->standIns->send(new Request('GET', 'https://shop.test/items/new'));

        $this->assertSame(['/items/7', '/items/8', '/items/new'], array_map(fn ($r) => $r->path, $this->shop->requests('GET', '/items/{id}')));
        $this->assertSame(['/items/7'], array_map(fn ($r) => $r->path, $this->shop->requests('GET', '/items/7')));
        $this->assertSame([], $this->shop->requests('POST', '/items/{id}'));
    }

    public function testTheContractCheckAndTheTraceNameARequestByItsRealPath(): void
    {
        $bare = new Backend('https://bare.test');
        $bare->bind('bare', $this->trace, null);
        $bare->reply('GET', '/items/{id}', 200, ['id' => '1']);
        $this->shop->reply('GET', '/items/{id}', 200, ['id' => 7]);

        (new StandIns([$bare]))->send(new Request('GET', 'https://bare.test/items/7'));
        $this->get('https://shop.test/items/7');

        $this->assertSame('GET /items/7', $this->trace->records()[0]->name);
        $this->assertStringStartsWith('GET /items/7 answered 200: ', $this->shop->violations()[0] ?? '');
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

    public function testHealLiftsEveryTimeOutAndKeepsTheReplies(): void
    {
        $this->shop->reply('GET', '/items/2', 200, ['id' => '2']);
        $this->shop->timeOut('GET', '/items/1');
        $this->shop->timeOut('GET', '/items/2', ['view' => 'full']);
        $this->shop->reply('GET', '/items/1', 200, ['id' => '1']);

        try {
            $this->get('https://shop.test/items/1');
            $this->fail('a reply lifted the time-out');
        } catch (Unreachable) {
        }
        $this->shop->heal();

        $this->assertSame('1', $this->get('https://shop.test/items/1')['id']);
        $this->assertSame('2', $this->get('https://shop.test/items/2?view=full')['id']);
    }

    public function testAnAnswerIsWorkedOutFromEachRequest(): void
    {
        $this->shop->replyWith('GET', '/items/{id}', fn ($r) => [200, ['id' => basename($r->path)]], ['X-Shard' => 'own']);

        foreach (['1', '2'] as $id) {
            $answer = $this->standIns->send(new Request('GET', "https://shop.test/items/{$id}"));
            $this->assertSame([200, $id, 'own'], [$answer->status, $answer->json()['id'], $answer->header('X-Shard')]);
        }
        $this->assertSame([], $this->shop->violations());
    }

    public function testAnAnswerMayChangeWithWhatReachedTheNeighbour(): void
    {
        $this->shop->replyWith('GET', '/items/1', fn () => count($this->shop->requests('GET', '/items/1')) === 1
            ? [404, ['code' => 7, 'message' => 'not yet']]
            : [200, ['id' => '1']]);

        $this->assertSame(404, $this->standIns->send(new Request('GET', 'https://shop.test/items/1'))->status);
        $this->assertSame(200, $this->standIns->send(new Request('GET', 'https://shop.test/items/1'))->status);
        $this->assertSame([], $this->shop->violations());
    }

    public function testAnAnswerWorkedOutIsAReplyNamingNoQuery(): void
    {
        $this->shop->replyWith('GET', '/items/1', fn () => [200, ['id' => 'worked out']]);
        $this->shop->replyTo('GET', '/items/1', ['view' => 'full'], 200, ['id' => 'full']);

        $this->assertSame('worked out', $this->get('https://shop.test/items/1?view=short')['id']);
        $this->assertSame('full', $this->get('https://shop.test/items/1?view=full')['id']);
        $this->shop->reply('GET', '/items/1', 200, ['id' => 'declared']);
        $this->assertSame('declared', $this->get('https://shop.test/items/1')['id']);
    }

    public function testTheContractChecksAnAnswerWorkedOut(): void
    {
        $this->shop->replyWith('GET', '/items/{id}', fn () => [200, ['id' => 7]]);

        $this->get('https://shop.test/items/7');

        $this->assertStringStartsWith('GET /items/7 answered 200: ', $this->shop->violations()[0] ?? '');
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
