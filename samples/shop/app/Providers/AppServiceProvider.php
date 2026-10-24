<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPConnectionConfig;
use PhpAmqpLib\Connection\AMQPConnectionFactory;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AbstractConnection::class, function () {
            $url = parse_url(config('services.rabbit.url'));
            $config = new AMQPConnectionConfig();
            $config->setHost($url['host']);
            $config->setPort($url['port'] ?? 5672);
            $config->setUser(rawurldecode($url['user'] ?? 'guest'));
            $config->setPassword(rawurldecode($url['pass'] ?? 'guest'));
            $config->setVhost(rawurldecode(ltrim($url['path'] ?? '/', '/')) ?: '/');
            return AMQPConnectionFactory::create($config);
        });
    }
}
