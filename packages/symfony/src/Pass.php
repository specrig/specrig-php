<?php

declare(strict_types=1);

namespace Specrig\Symfony;

use PhpAmqpLib\Connection\AbstractConnection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class Pass implements CompilerPassInterface
{
    public const TRANSPORTS = 'specrig.transports';

    public const SCHEDULES = 'specrig.schedules';

    /** @param list<class-string> $allowed */
    public function __construct(private readonly string $root, private readonly array $allowed)
    {
    }

    public function process(ContainerBuilder $container): void
    {
        $own = $this->namespaces();
        foreach ($container->getDefinitions() as $id => $definition) {
            $class = $container->getParameterBag()->resolveValue($definition->getClass() ?? $id);
            if (!is_string($class) || $definition->isAbstract() || $definition->isSynthetic()) {
                continue;
            }
            if (str_starts_with($class, 'PhpAmqpLib\\Connection\\') && is_a($class, AbstractConnection::class, true)) {
                $container->setDefinition($id, (new Definition($class))->setFactory([Seams::class, 'amqp'])->setPublic($definition->isPublic()));
            } elseif (array_filter($own, fn (string $namespace) => str_starts_with($class, $namespace)) !== []) {
                $definition->setPublic(true);
            }
        }

        if ($container->hasDefinition('clock')) {
            $container->setDefinition('clock', (new Definition(Clock::class))->setFactory([Seams::class, 'clock'])->setPublic(true));
        }

        $transports = [];
        $replaced = [];
        foreach ($container->findTaggedServiceIds('messenger.receiver') as $id => $tags) {
            $definition = $container->getDefinition($id);
            $dsn = $definition->getArguments()[0] ?? null;
            if (!is_string($dsn) || str_starts_with($dsn, 'sync://') || str_starts_with($dsn, 'schedule://')) {
                continue;
            }
            $definition->replaceArgument(0, 'in-memory://?serialize=true')->setPublic(true);
            $name = (string) ($tags[0]['alias'] ?? $id);
            $transports[$name] = $id;
            $replaced[$name] = $dsn;
        }
        $container->setParameter(self::TRANSPORTS, $transports);
        // A setting the replaced address read is still the service's, and a container
        // that no longer reads it refuses to compile.
        $container->setParameter('specrig.replaced', $replaced);

        $schedules = [];
        foreach ($container->findTaggedServiceIds('scheduler.schedule_provider') as $id => $tags) {
            $container->getDefinition($id)->setPublic(true);
            $schedules[(string) ($tags[0]['name'] ?? 'default')] = $id;
        }
        $container->setParameter(self::SCHEDULES, $schedules);

        foreach (['messenger.routable_message_bus', 'messenger.default_bus'] as $bus) {
            if ($container->has($bus)) {
                $container->setAlias('specrig.bus', $bus)->setPublic(true);
                break;
            }
        }

        if ($container->has('logger')) {
            $container->register('specrig.logs', LoggerInterface::class)
                ->setFactory([Seams::class, 'logs'])
                ->setArguments([new Reference('.inner')])
                ->setDecoratedService('logger');
        }

        foreach ($this->allowed as $type) {
            if ($container->has($type)) {
                $container->register("specrig.granted.{$type}", $type)
                    ->setFactory([Seams::class, 'granted'])
                    ->setArguments([$type, new Reference('.inner')])
                    ->setDecoratedService($type);
            }
        }
    }

    /** @return list<string> */
    private function namespaces(): array
    {
        $composer = json_decode((string) @file_get_contents("{$this->root}/composer.json"), true);
        return array_keys($composer['autoload']['psr-4'] ?? []);
    }
}
