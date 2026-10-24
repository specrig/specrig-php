<?php

namespace App;

use GuzzleHttp\ClientInterface;

final class Sessions
{
    public function __construct(private readonly ClientInterface $client)
    {
    }

    public function end(string $token): void
    {
        $this->client->request('DELETE', "/sessions/{$token}");
    }

    public function call(string $url): void
    {
        $this->client->get($url);
    }
}
