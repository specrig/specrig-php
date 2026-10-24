<?php

declare(strict_types=1);

namespace Specrig\Symfony;

use PhpAmqpLib\Connection\AbstractConnection;
use Psr\Log\LoggerInterface;
use Specrig\Postgres\Address;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;

// A compiled container is the run's, its services the case's: what the harness
// puts in at a seam is found here, from the harness of the case that runs.
final class Seams
{
    private static ?Harness $current = null;

    /** @var list<class-string> */
    private static array $allowed = [];

    private static string $root = '';

    private static string $directory = '';

    /** @param list<class-string> $allowed */
    public static function enter(Harness $harness, array $allowed, string $root, string $directory): void
    {
        self::$current = $harness;
        self::$allowed = $allowed;
        self::$root = $root;
        self::$directory = $directory;
    }

    public static function leave(): void
    {
        self::$current = null;
    }

    public static function current(): Harness
    {
        return self::$current ?? throw new \LogicException('the service reached a seam of the harness outside a case');
    }

    public static function root(): string
    {
        return self::$root;
    }

    public static function directory(): string
    {
        return self::$directory;
    }

    public static function build(ContainerBuilder $container): void
    {
        $container->prependExtensionConfig('framework', ['test' => true, 'http_client' => ['mock_response_factory' => 'specrig.responder']]);
        $container->register('specrig.responder', Responder::class)->setFactory([self::class, 'responder'])->setPublic(true);
        if (interface_exists(\Doctrine\DBAL\Driver\Middleware::class)) {
            $container->register('specrig.dbal', Dbal\Middleware::class)->addTag('doctrine.middleware');
        }
        $container->addCompilerPass(new Pass(self::$root, self::$allowed), PassConfig::TYPE_BEFORE_OPTIMIZATION, -1000);
    }

    public static function responder(): Responder
    {
        return new Responder(self::current()->standIns());
    }

    public static function amqp(): AbstractConnection
    {
        return (self::current()->buses()[0] ?? throw new \LogicException('the service connects to RabbitMQ and the harness has no Bus'))->connection();
    }

    public static function clock(): Clock
    {
        return new Clock(self::current()->clock);
    }

    public static function logs(LoggerInterface $inner): LoggerInterface
    {
        return new Logs($inner, self::current());
    }

    public static function granted(string $type, object $inner): object
    {
        return self::current()->replacement($type) ?? $inner;
    }

    /**
     * @param array<string, mixed> $params
     * @return array{?Address, ?\Throwable}
     */
    public static function fault(array $params): array
    {
        return self::$current?->fault($params) ?? [null, null];
    }
}
