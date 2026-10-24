<?php

declare(strict_types=1);

namespace Specrig\Tests;

use PHPUnit\Framework\TestCase;
use Specrig\Proxy;
use Specrig\Rig;

final class ProxyTest extends TestCase
{
    public function testARequestThroughTheProxyIsRefusedAndWrittenDown(): void
    {
        $proxy = Proxy::start();
        $proxy->mark();
        $address = $proxy->environment()['https_proxy'];

        foreach (['https://maps.example.com/tiles/1', 'http://maps.example.com/tiles/2'] as $url) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [CURLOPT_PROXY => $address, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
            curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $this->assertTrue(curl_errno($curl) !== 0 || $status === 502, "{$url} went past the proxy");
        }

        $this->assertSame(['https://maps.example.com', 'GET http://maps.example.com/tiles/2'], $proxy->refused());
        Rig::endRun();
    }
}
