<?php

declare(strict_types=1);

namespace Specrig\Tests;

use PHPUnit\Framework\TestCase;
use Specrig\Contract;
use Specrig\Json;

final class ContractTest extends TestCase
{
    private const FILE = __DIR__ . '/fixtures/shop.yaml';

    private Contract $shop;

    protected function setUp(): void
    {
        $this->shop = new Contract(self::FILE, 'shop', 'Error');
    }

    public function testAnAnswerTheNeighbourCanGivePasses(): void
    {
        $this->assertSame([], $this->shop->response('GET', '/items/1', 404, Json::decode('{"code": 404, "message": "not found"}')));
    }

    public function testAnAnswerInAnotherShapeThanTheContractsIsRefused(): void
    {
        $this->assertNotSame([], $this->shop->response('GET', '/items/1', 404, Json::decode('{"code": "not_found"}')));
    }

    public function testAStatusTheRouteDoesNotAnswerIsRefusedAFailureOfTheServerIsNot(): void
    {
        $this->assertSame(['GET /items/1 answered 418: shop does not answer 418 here'], $this->shop->response('GET', '/items/1', 418, new \stdClass()));
        $this->assertSame([], $this->shop->response('GET', '/items/1', 503, Json::decode('{"code": 503, "message": "unavailable"}')));
        $this->assertNotSame([], $this->shop->response('GET', '/items/1', 503, Json::decode('{"reason": "unavailable"}')));
    }

    public function testWithoutAFailureSchemaAFailureTheRouteDoesNotDocumentIsRefused(): void
    {
        $this->assertNotSame([], (new Contract(self::FILE, 'shop'))->response('GET', '/items/1', 503, Json::decode('{"code": 503, "message": "x"}')));
    }

    public function testARequestBodyTheNeighbourWouldNotAcceptIsRefused(): void
    {
        $this->assertNotSame([], $this->shop->request('POST', '/items/new', Json::decode('{"count": 1}')));
        $this->assertSame([], $this->shop->request('POST', '/items/new', Json::decode('{"name": "box", "count": 1}')));
    }

    public function testARouteTheNeighbourDoesNotServeIsRefused(): void
    {
        $this->assertSame(['DELETE /items/1: shop serves no such route'], $this->shop->request('DELETE', '/items/1', null));
    }

    public function testTheTemplateWithTheMostConstantSegmentsTakesTheRequest(): void
    {
        $this->assertSame([], $this->shop->response('GET', '/items/new', 200, Json::decode('{"draft": true}')));
        $this->assertNotSame([], $this->shop->response('GET', '/items/new', 200, Json::decode('{"id": "new"}')));
        $this->assertSame('GET /items/{id}', $this->shop->name('GET', '/items/1'));
    }
}
