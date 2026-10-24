<?php

namespace App;

use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class Warehouse
{
    public function __construct(
        private readonly HttpClientInterface $warehouseClient,
        private readonly AbstractConnection $connection,
        #[Autowire(env: 'int:RESERVE_LIMIT')] private readonly int $limit,
    ) {
    }

    public function stock(string $sku): array
    {
        return $this->warehouseClient->request('GET', '/stock/' . $sku)->toArray();
    }

    public function reserve(): void
    {
        $this->call('PUT', '/stock/reserve');
        $this->connection->channel()->basic_publish(new AMQPMessage('{}'), 'stock', 'stock.reserved');
    }

    private function call(string $method, string $path): void
    {
        $this->warehouseClient->request($method, $path);
    }
}
