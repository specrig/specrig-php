<?php

declare(strict_types=1);

namespace Specrig\Symfony;

use Specrig\Amqp\Bus;
use Specrig\Databases as DatabaseSeam;
use Specrig\Handlers;
use Specrig\Http\Client;
use Specrig\Http\Inbound;
use Specrig\Http\Request;
use Specrig\Http\Response;
use Specrig\Logged;
use Specrig\Postgres\Address;
use Specrig\Rig;
use Specrig\Worked;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\Clock as GlobalClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Messenger\ScheduledStamp;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

abstract class Harness extends Rig
{
    private const WORK = 1000;

    private static ?string $directory = null;

    private ?KernelInterface $kernel = null;

    /** @var list<Logged> */
    private array $logs = [];

    private string $output = '';

    /** @var array<string, array{?Address, ?\Throwable}> */
    private array $faults = [];

    /** @return class-string<KernelInterface> */
    protected function kernel(): string
    {
        return 'App\Kernel';
    }

    public function container(): ContainerInterface
    {
        $this->boot();
        $container = ($this->kernel ?? throw new \LogicException("{$this->service()} did not boot"))->getContainer();
        return $container->has('test.service_container') ? $container->get('test.service_container') : $container;
    }

    public function get(string $id): mixed
    {
        return $this->container()->get($id);
    }

    public function http(): Client
    {
        return new Client(fn (Request $request) => Inbound::exchange($this->trace, $request, fn (Request $r) => $this->handle($r)));
    }

    public function run(string|object $job): void
    {
        $this->traced('command', (new \ReflectionClass($job))->getShortName(), null, function () use ($job) {
            if (is_object($job)) {
                $this->dispatch(new Envelope($job, [new ReceivedStamp('specrig')]));
                return;
            }
            if ($this->container()->has($job)) {
                $instance = $this->get($job);
                foreach (['__invoke', 'run', 'execute', 'handle'] as $method) {
                    if (method_exists($instance, $method)) {
                        $instance->{$method}();
                        return;
                    }
                }
                throw new \LogicException("{$job} has no __invoke, run, execute or handle to run it by");
            }
            foreach ($this->scheduled() as [$name, $context, $message]) {
                if ($message instanceof $job) {
                    $this->dispatch(new Envelope($message, [new ReceivedStamp("scheduler_{$name}"), new ScheduledStamp($context)]));
                    return;
                }
            }
            throw new \LogicException("{$job} is neither a service of {$this->service()} nor a message its schedules send");
        });
    }

    public function command(string $name, string ...$args): int
    {
        return $this->traced('command', $name, $args === [] ? null : $args, function () use ($name, $args) {
            [$code, $this->output] = $this->console($name, ...$args);
            return $code;
        });
    }

    public function output(): string
    {
        return $this->output;
    }

    public function work(): array
    {
        $this->boot();
        $worked = [];
        $transports = $this->kernel?->getContainer()->getParameter(Pass::TRANSPORTS) ?? [];
        for ($i = 0, $more = true; $more && $i < self::WORK; $i++) {
            $more = false;
            foreach ($transports as $name => $id) {
                $transport = $this->get($id);
                assert($transport instanceof TransportInterface);
                foreach ($transport->get() as $envelope) {
                    $more = true;
                    $job = (new \ReflectionClass($envelope->getMessage()))->getShortName();
                    try {
                        $this->traced('command', $job, null, fn () => $this->dispatch(
                            $envelope->with(new ReceivedStamp((string) $name), new ConsumedByWorkerStamp()),
                        ));
                        $transport->ack($envelope);
                        $worked[] = new Worked($job);
                    } catch (\Throwable $e) {
                        $transport->reject($envelope);
                        $worked[] = new Worked($job, $e);
                    }
                }
            }
        }
        return $worked;
    }

    /** @return list<Logged> */
    public function logs(): array
    {
        return $this->logs;
    }

    public function databases(): DatabaseSeam
    {
        return new Databases($this);
    }

    /** @return list<string> */
    public function commands(): array
    {
        return array_map('strval', array_keys($this->application()->all()));
    }

    /** @return array{int, string} */
    public function console(string $name, string ...$args): array
    {
        $output = new BufferedOutput();
        $code = $this->application()->run(new ArgvInput(['bin/console', $name, ...$args]), $output);
        return [$code, $output->fetch()];
    }

    public function logged(Logged $logged): void
    {
        $this->logs[] = $logged;
    }

    public function replacement(string $type): ?object
    {
        return $this->replaced()[$type] ?? null;
    }

    /** @return list<Bus> */
    public function buses(): array
    {
        return $this->resources(Bus::class);
    }

    public function faulted(string $address, ?Address $refusing, ?\Throwable $error): void
    {
        [$was, $failed] = $this->faults[$address] ?? [null, null];
        $this->faults[$address] = [$refusing ?? $was, $error ?? $failed];
    }

    /**
     * @param array<string, mixed> $params
     * @return array{?Address, ?\Throwable}
     */
    public function fault(array $params): array
    {
        foreach ($this->faults as $address => $fault) {
            $at = Address::parse($address);
            if (($params['host'] ?? null) === $at->host && (int) ($params['port'] ?? 5432) === $at->port && ($params['dbname'] ?? null) === $at->database) {
                return $fault;
            }
        }
        return [null, null];
    }

    protected function framework(): array
    {
        return ['APP_ENV' => 'prod', 'APP_DEBUG' => '0'];
    }

    protected function shipped(): array
    {
        $values = [];
        foreach ($this->dotenvs() as $file) {
            $values += (new Dotenv())->parse((string) file_get_contents($file), $file);
        }
        return array_map('strval', array_keys($values));
    }

    protected function start(): void
    {
        Seams::enter($this, $this->allow(), static::root(), self::directory());
        $files = $this->dotenvs();
        if ($files !== []) {
            (new Dotenv())->usePutenv()->load(...$files);
        }
        $class = Kernels::rigged($this->kernel(), self::directory());
        $kernel = new $class('prod', false);
        try {
            Handlers::kept(fn () => $kernel->boot());
        } catch (\Throwable $e) {
            Seams::leave();
            throw $e;
        }
        $this->kernel = $kernel;
        if (class_exists(GlobalClock::class)) {
            GlobalClock::set(new Clock($this->clock));
        }
        if (class_exists(\Carbon\Carbon::class)) {
            $this->clock->follow(fn (?\DateTimeImmutable $at) => \Carbon\Carbon::setTestNow($at === null ? null : \Carbon\Carbon::instance($at)));
        }
    }

    protected function keepRunning(string $command): void
    {
        foreach ($this->application()->all() as $name => $registered) {
            $registered = $registered instanceof LazyCommand ? $registered->getCommand() : $registered;
            if ($registered instanceof $command) {
                [$code, $output] = $this->console((string) $name);
                if ($code !== 0) {
                    throw new \RuntimeException("{$name}, which running() names, exited: " . trim($output));
                }
                return;
            }
        }
        throw new \LogicException("running() names {$command}, which {$this->service()} registers as no command");
    }

    protected function stop(): void
    {
        $kernel = $this->kernel;
        $this->kernel = null;
        $this->logs = [];
        $this->faults = [];
        try {
            $kernel?->shutdown();
        } finally {
            Seams::leave();
            if (class_exists(GlobalClock::class)) {
                GlobalClock::set(new NativeClock());
            }
        }
    }

    /** @return array{Response, ?string} */
    private function handle(Request $request): array
    {
        $this->boot();
        $kernel = $this->kernel ?? throw new \LogicException("{$this->service()} did not boot");
        $server = [];
        foreach ($request->headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : "HTTP_{$key}"] = $value;
        }
        $symfony = SymfonyRequest::create($request->url, $request->method, [], [], [], $server, $request->body);
        $response = $kernel->handle($symfony);
        $content = $response->getContent();
        if ($content === false) {
            ob_start();
            $response->sendContent();
            $content = (string) ob_get_clean();
        }
        $kernel->terminate($symfony, $response);
        $headers = [];
        foreach ($response->headers->all() as $name => $values) {
            $headers[(string) $name] = implode(', ', $values);
        }
        $named = null;
        $routed = $symfony->attributes->get('_route');
        $route = is_string($routed) ? $this->get('router')->getRouteCollection()->get($routed) : null;
        if ($route !== null) {
            $named = Inbound::route($route->getPath(), array_keys(array_filter($route->getRequirements(), fn ($r) => in_array($r, ['.*', '.+'], true))));
        }
        return [new Response($response->getStatusCode(), $headers, $content), $named];
    }

    private function dispatch(Envelope $envelope): void
    {
        $bus = $this->get('specrig.bus');
        assert($bus instanceof MessageBusInterface);
        try {
            $bus->dispatch($envelope);
        } catch (HandlerFailedException $e) {
            throw $e->getWrappedExceptions()[array_key_first($e->getWrappedExceptions())] ?? $e;
        }
    }

    /** @return iterable<array{string, MessageContext, object}> */
    private function scheduled(): iterable
    {
        foreach ($this->kernel?->getContainer()->getParameter(Pass::SCHEDULES) ?? [] as $name => $id) {
            $provider = $this->get($id);
            assert($provider instanceof ScheduleProviderInterface);
            foreach ($provider->getSchedule()->getRecurringMessages() as $recurring) {
                $context = new MessageContext((string) $name, $recurring->getId(), $recurring->getTrigger(), $this->clock->now());
                foreach ($recurring->getMessages($context) as $message) {
                    yield [(string) $name, $context, $message];
                }
            }
        }
    }

    private function application(): Application
    {
        $this->boot();
        $application = new Application($this->kernel ?? throw new \LogicException("{$this->service()} did not boot"));
        $application->setAutoExit(false);
        return $application;
    }

    /** @return list<string> */
    private function dotenvs(): array
    {
        $root = static::root();
        $base = is_file("{$root}/.env") ? "{$root}/.env" : (is_file("{$root}/.env.dist") ? "{$root}/.env.dist" : null);
        return array_values(array_filter([$base, is_file("{$root}/.env.prod") ? "{$root}/.env.prod" : null]));
    }

    private static function directory(): string
    {
        if (self::$directory === null) {
            $directory = self::$directory = sys_get_temp_dir() . '/specrig-symfony-' . getmypid();
            Rig::ending(function () use ($directory) {
                self::$directory = null;
                Kernels::remove($directory);
            });
        }
        return self::$directory;
    }
}
