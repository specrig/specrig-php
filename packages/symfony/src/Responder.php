<?php

declare(strict_types=1);

namespace Specrig\Symfony;

use Specrig\Http\Request;
use Specrig\Http\Unreachable;
use Specrig\StandIns;
use Symfony\Component\HttpClient\Response\MockResponse;

final class Responder
{
    public function __construct(private readonly StandIns $standIns)
    {
    }

    /** @param array<string, mixed> $options */
    public function __invoke(string $method, string $url, array $options): MockResponse
    {
        $headers = [];
        foreach ($options['normalized_headers'] ?? [] as $values) {
            [$name, $value] = explode(': ', (string) $values[0], 2) + [1 => ''];
            $headers[$name] = $value;
        }
        try {
            $answer = $this->standIns->send(new Request($method, $url, $headers, self::body($options['body'] ?? '')));
            return new MockResponse($answer->body, ['http_code' => $answer->status, 'response_headers' => $answer->headers]);
        } catch (Unreachable $e) {
            return new MockResponse('', ['error' => ($e->timedOut ? 'Idle timeout reached' : 'Could not connect') . " for \"{$url}\": {$e->getMessage()}"]);
        }
    }

    private static function body(mixed $body): string
    {
        if (is_string($body)) {
            return $body;
        }
        $read = '';
        if ($body instanceof \Closure) {
            while (($chunk = $body(16384)) !== '') {
                $read .= $chunk;
            }
        } elseif (is_iterable($body)) {
            foreach ($body as $chunk) {
                $read .= $chunk;
            }
        }
        return $read;
    }
}
