<?php

namespace App;

use PhpAmqpLib\Connection\AMQPStreamConnection;

final class Rabbit
{
    public static function connect(string $url): AMQPStreamConnection
    {
        $parts = parse_url($url);
        return new AMQPStreamConnection(
            $parts['host'],
            $parts['port'] ?? 5672,
            rawurldecode($parts['user'] ?? 'guest'),
            rawurldecode($parts['pass'] ?? 'guest'),
            rawurldecode(ltrim($parts['path'] ?? '/', '/')) ?: '/',
        );
    }
}
