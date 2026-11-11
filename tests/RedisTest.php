<?php

declare(strict_types=1);

namespace Specrig\Tests;

use PHPUnit\Framework\TestCase;
use Specrig\Environment;
use Specrig\Redis;
use Specrig\Rig;

final class RedisTest extends TestCase
{
    public static function tearDownAfterClass(): void
    {
        Rig::endRun();
    }

    /** @return resource|false */
    private function connect()
    {
        $parts = parse_url((string) Environment::get('REDIS_URL'));
        return @stream_socket_client("tcp://{$parts['host']}:{$parts['port']}", $code, $message, 2);
    }

    /** @param resource $socket */
    private function ping($socket): string|false
    {
        @fwrite($socket, "PING\r\n");
        $answer = @fgets($socket);
        return $answer === false ? false : trim($answer);
    }

    public function testAFaultAfterTheBootCutsWhatTheServiceHoldsAndRefusesWhatItOpensNext(): void
    {
        $cache = new Redis('REDIS_URL');
        $rig = new Rigged(cache: $cache);
        $rig->boot();
        $held = $this->connect();
        $this->assertSame('+PONG', $this->ping($held));

        $cache->failWith();

        $this->assertFalse($this->ping($held));
        $this->assertFalse($this->connect());
        $rig->dispose();
    }

    public function testAFaultBeforeTheBootWaitsForItAndTheBootFindsRedisUp(): void
    {
        $cache = new Redis('REDIS_URL');
        $rig = new Rigged(cache: $cache);
        $cache->failWith();

        $rig->boot();

        $this->assertFalse($this->connect());
        $rig->dispose();
    }

    public function testTheNextCaseGetsItsRedisBackEmpty(): void
    {
        $cache = new Redis('REDIS_URL');
        $rig = new Rigged(cache: $cache);
        $rig->boot();
        $held = $this->connect();
        @fwrite($held, "SET kept 1\r\n");
        @fgets($held);
        $cache->failWith();
        $rig->dispose();

        $next = new Rigged(cache: new Redis('REDIS_URL'));
        $next->boot();

        $connection = $this->connect();
        $this->assertSame('+PONG', $this->ping($connection));
        @fwrite($connection, "EXISTS kept\r\n");
        $this->assertSame(':0', trim((string) fgets($connection)));
        $next->dispose();
    }

    public function testTheServiceGetsTheAddressItWasGivenWithTheGatesHostAndPort(): void
    {
        $cache = new Redis('REDIS_URL', 'redis://user:p%40ss@10.0.0.9:6380/3?timeout=2');

        $address = $cache->provision('specrig');

        $this->assertMatchesRegularExpression('#^redis://user:p%40ss@127\.0\.0\.1:\d+/3\?timeout=2$#', $address);
    }
}
