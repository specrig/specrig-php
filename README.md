# specrig for PHP

A harness for a Laravel or Symfony service developed specification first: the
cases are written and approved before the code, and the code is changed until
they pass. A service is whatever its own bootstrap builds — a web API, a consumer,
a queue worker, a schedule, a console command a CronJob runs — and usually several
of them in one application.

**Status: built.** Everything below runs green on two samples — `shop`, a Laravel
API over Postgres with queued jobs and a RabbitMQ consumer, and `reminders`, a
Symfony service over Postgres and Redis with Messenger, a schedule, a command and
a RabbitMQ consumer. [docs/design.md](docs/design.md) has what each part is built
from and why; what every specrig stack agrees on — the records, the modes, the
text of `specrig.md` — is the
[specrig protocol](https://github.com/specrig/protocol), whose modes and
`specrig.md` parts are copied into
[packages/specrig/protocol/](packages/specrig/protocol).

- **The service runs as it ships.** A case boots the application its own
  `bootstrap/app.php` or `Kernel` builds, in production mode: the real container,
  a real database in a container. Only the edges are replaced.
- **It is driven the way production drives it.** A request through its own HTTP
  kernel, an event routed by the service's own bindings to its own consumer, a job
  run as its worker runs it, a command through its own console. Work the service
  queues runs when a step runs the worker.
- **Neighbours are stood in for by their contract.** Every origin the service
  calls is named in `tests/internal/Harness.php`. Every client the framework
  builds — Laravel's `Http`, Symfony's `HttpClientInterface` and its scoped
  clients — reaches a stand-in. A step declares what a neighbour answers; the
  answer and every request the service sends are checked against the neighbour's
  OpenAPI document. A request to an origin the harness does not name, a call no
  step answered, or anything the contract does not allow fails the case. A client
  the service builds by hand is refused by the harness's proxy before anything
  leaves. What the service publishes is recorded.
- **What a case may replace is granted.** Beyond the stand-ins, a case replaces a
  service of the container only if the harness grants its type; any other
  replacement fails the step, naming the type.
- **Every setting the service reads is answered.** The harness points the
  service's environment variables at the stand-ins and the resources, unsets
  every other one the code reads, and a check fails the boot when the code needs a
  variable that neither the harness nor the service's shipped defaults answer.
- **Two records in specrig's format.** `tests/surface.yaml` is what the code can
  serve, consume, publish and call, read from the code. `tests/flows.yaml` is
  what the cases make the service send, step by step. A project's flow map reads
  them beside its other services' records — specrig-go's `flows` does.
- **A protocol for the people and agents who write the cases.** `init` writes
  `specrig.md`, which holds an agent to the order: cases, approval, then code, and
  one line in the service's `AGENTS.md` brings it in; `specrig agents` rewrites it.

## Ways in and out

| way in | a step drives it with | named in the records |
|---|---|---|
| an HTTP route | `$harness->http()->get(...)` | `http GET /orders/{id}` |
| an event | `$harness->bus->deliver($routingKey, $body)` | `event invoices.paid` |
| a job | `$harness->run(SendReminders::class)` | `command SendReminders` |
| what the service queued | `$harness->work()` | `command InvoiceOrder` |
| a command | `$harness->command('app:remind', ...$args)` | `command app:remind` |

| way out | in a case |
|---|---|
| a neighbour over HTTP | a stand-in, checked by the neighbour's contract |
| an event | the bus stand-in, which records it |
| a database, a cache | a resource: the real system, in a container |
| a queue | the harness's own, which keeps what was queued for `work()` |
| anything else — mail, object storage, a vendor's SDK | a granted seam |

## Packages

| package | what it is |
|---|---|
| `specrig/specrig` | the core, independent of the framework: the records, the contract check, the stand-ins, the proxy, the Postgres and Redis resources, the RabbitMQ stand-in, the Behat extension, the surface pass, and `vendor/bin/specrig` — `init`, `surface`, `agents`, and `rules` of the protocol's rules/1 |
| `specrig/laravel` | the harness for Laravel 12 and 13 |
| `specrig/symfony` | the harness for Symfony 7.4 and 8 |

PHP 8.2 and up; Behat 3.34 and up, or 4.

## Getting started

In the service's directory:

```bash
composer require --dev specrig/laravel
```

or `specrig/symfony`, then:

```bash
vendor/bin/specrig init --backend billing=BILLING_URL --contract billing=../docs/billing.yaml
```

`init` reads what the service is from its code, through the same pass as
`surface`: the environment variables it reads, the routing keys it binds, the
packages it uses. `--backend` names a neighbour the service calls over HTTP and
the variable that holds its address; a variable the code does not read is
refused, with the list of those it does, and a service that calls out with no
`--backend` is refused, naming the calls. Postgres becomes a `Postgres` resource
— Laravel's `DB_CONNECTION=pgsql` in `.env.example`, Symfony's `postgres://`
`DATABASE_URL` in `.env` —, Redis a `Redis` where Laravel's `.env.example` puts a
store, the queue or the session on it or Symfony reads `REDIS_URL`, and
php-amqplib a `Bus` on the variable that names RabbitMQ or AMQP; the console
commands that bind a queue go into `running()`. Laravel gets an `APP_KEY` of its
own; a Symfony setting the code needs and `.env` does not ship becomes a constant
the harness answers empty.

| option | |
|---|---|
| `--backend <name>[=<setting>]` | an HTTP neighbour and the variable that points at it; repeatable |
| `--contract <name>=<path>` | its OpenAPI document, from the service's directory |
| `--failure <name>=<schema>` | the schema of a 5xx it may answer on any route |
| `--service <name>` | the service's name in its records; the package's name by default |

`init` writes no file that exists, save `specrig.md` and the line an `AGENTS.md`
lacks. It writes:

| file | who changes it |
|---|---|
| `tests/internal/Harness.php` | whoever owns the platform: a new origin, a resource, a grant, a consumer kept running |
| `tests/behat.php`, the rest of `tests/internal/` | nobody, as a rule |
| `tests/internal/boot.feature` | nobody: the case that boots the service with no step of its own |
| `tests/features/` | the cases |
| `tests/steps/` | the steps |
| `specrig.yaml`, naming the tool the plugin asks | whoever owns the platform: an area, a client's neighbour |
| `AGENTS.md`, and `CLAUDE.md` linked to it; to an `AGENTS.md` there already, the line that brings `specrig.md` in | whoever owns the platform: what the service is for |
| `specrig.md` | nobody: `specrig agents` rewrites it |

and `tests/surface.yaml` and `tests/checks.yaml`, and adds `behat/behat`,
`webmozart/assert` and the harness to `require-dev` — `composer update` with
them is the one step left, and `init` prints it. It maps the tests' namespace
`Internal\` and `Steps\` to `tests/internal/` and `tests/steps/` in
`autoload-dev`: PSR-4 alone would look for `tests/Internal/` and `tests/Steps/`,
which on Linux are other directories.

Then:

```bash
php vendor/bin/specrig surface
```

```bash
php vendor/bin/specrig agents
```

```bash
php vendor/bin/behat -c tests/behat.php
```

The first rewrites `tests/surface.yaml` and `tests/checks.yaml` from the code, the
second `specrig.md` from `specrig.yaml`, and the third runs the suite and writes
`tests/flows.yaml`. How and where they run — a `Makefile`, Composer scripts, CI —
is the project's. The `agents` command fails while `AGENTS.md` does not bring
`specrig.md` in, or still carries Rule #11 from before there was one.

They run on the PHP and Composer the service is developed with, so an IDE runs and
debugs the same suite. That PHP needs what the service needs, and `pdo_pgsql` for
a `Postgres`, `sockets` for php-amqplib. The resources start in Docker from the
suite's own process, through the Docker socket: `DOCKER_HOST`, else where Docker
Desktop, OrbStack, Colima or Rancher Desktop put it.

For CI or a machine without PHP, `tests/internal/Dockerfile` builds an image with
PHP 8.4, unless `composer.json` pins another with `~`, and the extensions it and its
lock require. Run in it, the suite needs the repository the service lives in at its
own path, so a contract at the repository's root is there, and the host's Docker
socket for the resources:

```bash
REPO="$(git rev-parse --show-toplevel)"
docker build -t shop-specrig tests/internal
docker run --rm -v "$REPO:$REPO" -v /var/run/docker.sock:/var/run/docker.sock \
  -e TESTCONTAINERS_HOST_OVERRIDE=host.docker.internal --add-host=host.docker.internal:host-gateway \
  -w "$PWD" shop-specrig sh -c 'composer install --no-interaction --quiet && php vendor/bin/behat -c tests/behat.php'
```

The packages are not published yet. From a checkout of this repository, `init`
run as

```bash
php ../specrig-php/packages/specrig/bin/specrig init . --backend billing=BILLING_URL
```

adds the checkout's `packages/*` as a path repository and asks for `@dev`; an image
run then needs the checkout mounted at its own path too.

## Writing a case

A case is a scenario in `tests/features/`:

```gherkin
Feature: Reminders

  Scenario: A customer whose invoice is overdue is reminded
    Given billing holds an unpaid invoice of 120 for order 7, due yesterday
    And billing accepts reminders
    When the reminders run
    And the worker runs
    Then billing is asked to remind the customer of 120 for order 7
    And the reminder of 120 for order 7 is announced
```

A step no class defines leaves the case undefined, and Behat names it. A steps
class in `tests/steps/` takes the harness in its constructor, and every class
there is a context of every suite with no line in `tests/behat.php`:

```php
final class Reminders implements Context
{
    public function __construct(private readonly Harness $harness)
    {
    }

    #[Given('billing holds an unpaid invoice of :amount for order :order, due :when')]
    public function unpaid(int $amount, int $order, string $when): void
    {
        $this->harness->billing->replyTo('GET', '/invoices', ['status' => 'unpaid'], 200, [
            ['order' => $order, 'amount' => $amount, 'dueOn' => date('Y-m-d', strtotime($when))],
        ]);
    }

    #[Given('billing accepts reminders')]
    public function accepts(): void
    {
        $this->harness->billing->reply('POST', '/reminders', 202);
    }

    #[When('the reminders run')]
    public function run(): void
    {
        $this->harness->run(SendReminders::class);
    }

    #[When('the worker runs')]
    public function work(): void
    {
        foreach ($this->harness->work() as $worked) {
            Assert::false($worked->failed(), "{$worked->job}: {$worked->error?->getMessage()}");
        }
    }

    #[Then('billing is asked to remind the customer of :amount for order :order')]
    public function reminded(int $amount, int $order): void
    {
        $requests = $this->harness->billing->requests('POST', '/reminders');
        Assert::count($requests, 1);
        Assert::same($requests[0]->body(), ['order' => $order, 'amount' => $amount]);
    }
}
```

Assertions are `Webmozart\Assert\Assert`: PHPUnit's need PHPUnit's runner to fail
with a message, and Behat is not it.

| | |
|---|---|
| `$harness-><backend>->reply($method, $path, $status, $body)` | what the neighbour answers |
| `$harness-><backend>->replyTo($method, $path, $query, $status, $body)` | the same, for a request whose query has these values; the reply naming the most of them wins |
| `$harness-><backend>->timeOut($method, $path, $query?)` | the neighbour does not answer in time: the client meets the timeout it would meet, at once |
| `$harness-><backend>->requests($method, $path)` | what reached the neighbour, with query and body |
| `$harness->http()` | a client of the service's own HTTP kernel: `get`, `post`, `put`, `patch`, `delete`, `send`; a response with `status`, `body`, `json()` |
| `$harness->run($job)` | a job by its class or an instance — see below |
| `$harness->work()` | what the service queued, run as its worker runs it, until nothing is left; returns each job and how it ended |
| `$harness->command($name, ...$args)` | a console command; returns its exit code, and `output()` what it wrote |
| `$harness->bus->deliver($routingKey, $body, $exchange?, $headers?)` | a delivery, routed by the bindings the service made to the consumers on the queues it reaches; returns how they settled it: `Settled::Acked`, `Requeued` or `Dropped` |
| `$harness->bus->published($routingKey?)` | what the service published, in order: exchange, routing key, headers and body |
| `$harness->get($id)` | a service of the container: the repositories, the entity manager, a model's connection — what a `Given` writes through and a `Then` reads back from |
| `$harness->boot()` | the service, booted now: for a `Given` that writes through Eloquent's static models |
| `$harness->replace($type, $instance)` | a granted service of the container, replaced before the service boots |
| `$harness->logs()` | what the service logged, under its real logger |
| `$harness->clock` | the service's clock: the wall clock until a step calls `set()`, then `advance()` |
| `$harness-><resource>->failWith($error?)` | the resource refuses the service — see Postgres and Redis |
| `$harness->bus->failWith($error?)` | publishing throws what a closed connection throws, or the error given, until the next case |

The service boots at the first step that reaches it, so a `Given` declares and
replaces before anything is built. Each case boots a service of its own: a
singleton holding state does not carry it into the next case, and the static
state Laravel's own `TestCase` flushes is flushed too. The resources are
provisioned once per run and emptied before every case.

A call no step answered gets a 501 and fails the case, named. After the case,
every request and every answer is checked against the neighbour's contract: an
unknown route or method, an undocumented status, or a body of the wrong shape
fails it. So does a request the service sends past the stand-ins — a
`new GuzzleHttp\Client()` or `HttpClient::create()` of its own —, which the
harness's proxy refused.

PHP runs a request to its end: when a step returns, the service has done all the
request made it do, and nothing is in flight. So a neighbour cannot be held while
a step answers another; `timeOut` gives a case the slow neighbour instead.

**`Given` writes through the container's own repositories**, never through the
operation under test, and **`Then` reads back through them**, not out of the
answer: it catches "answered right, persisted wrong".

## `Harness.php`

```php
final class Harness extends \Specrig\Symfony\Harness
{
    public function __construct(
        public readonly Backend $billing = new Backend('https://billing.reminders.test', setting: 'BILLING_URL', contract: '../docs/billing.yaml', failure: 'Error'),
        public readonly Postgres $database = new Postgres('DATABASE_URL'),
        public readonly Redis $cache = new Redis('REDIS_URL'),
        public readonly Bus $bus = new Bus('RABBIT_URL'),
    ) {
        parent::__construct();
    }

    public function service(): string
    {
        return 'reminders';
    }

    protected function constants(): array
    {
        return ['REMINDERS_LIMIT' => '1'];
    }

    protected function allow(): array
    {
        return [FraudCheck::class];
    }

    protected function running(): array
    {
        return [ConsumePaymentsCommand::class];
    }
}
```

A Laravel service's extends `\Specrig\Laravel\Harness`; the rest is the same.

| member | |
|---|---|
| a `Backend` | an HTTP neighbour: its origin, the variable that points the service at it, its OpenAPI document, the schema of its 5xx |
| a `Postgres` or `Redis` | a real system, and the variable that receives its address |
| a `Bus` | RabbitMQ, stood in for in process in place of the php-amqplib connection the container holds, and the variable that holds its address, answered with one that leads nowhere |
| `constants()` | the other variables the harness answers, as given |
| `allow()` | the services of the container a case may replace |
| `running()` | the console commands that consume, run at the boot as production runs them |
| `kernel()` | Symfony: the kernel's class, where it is not `App\Kernel` |

A grant is a class name, so a renamed interface breaks the harness instead of
lingering in a list.

## Background work

Nothing runs by itself in a case: a worker that wakes on its own would send, at a
moment no case chose, what no step answered — a failure with a true message and
no cause in the case. So a step runs it.

**A job.** `$harness->run(SendReminders::class)`. Laravel resolves it from the
container and calls `handle`, `__invoke`, `run` or `execute` with the container's
method injection; an instance of a `ShouldQueue` job runs serialised and fired,
as the worker runs it. Symfony takes a service of the container by `__invoke`, `run`, `execute`
or `handle`; a message class one of the service's schedules sends is dispatched
as its scheduler dispatches it; a message instance is dispatched as a worker hands
it on.

**What the service queued.** Laravel's queue connections — every one but `sync`,
`null`, `deferred`, `background` and `failover` — are replaced by one of the
harness's own that keeps the payloads, so a job dispatched with a delay waits for
the harness's clock. Symfony's async Messenger transports are replaced by
in-memory ones that serialise. `$harness->work()` runs what is there, as the
worker would, until nothing is left: what a job queues runs in the same call.
A job that throws is returned, not thrown; the step says whether that is a
failure.

**A consumer.** A php-amqplib consumer is a long-running command: it declares,
binds, `basic_consume`s and loops on `wait()`. The commands `running()` names run
at the boot over the bus stand-in's connection, which says the channel is not
consuming, so the loop ends at once with its exchanges, queues, bindings and
consumers kept. `$harness->bus->deliver` routes a delivery by those bindings, as
the exchange would — a topic pattern with `*` and `#`, a direct key, a fanout —
and calls the callback of every consumer on the queues it reaches. So a case
exercises the service's own dispatch, and a binding with a typo is a red case. A
key no binding takes throws, naming the bindings there are; a key two exchanges
take wants the exchange named. A delivery the consumer leaves unsettled fails the
case after it, named, unless it consumes with `no_ack`.

What the service publishes is recorded and not delivered back to it by itself: a
step delivers it, so the case says that the second half happens.

**A command** runs through the service's own console — `artisan`, `bin/console` —
in the case's application, and its exit code is the step's result.

## Settings

A PHP service reads its settings from the environment, and the harness sets the
environment before the boot: its answers, and every other variable
`tests/checks.yaml` says the code reads unset, so a developer's own
`BILLING_URL` does not send a case to the real billing and the service sees the
default its code ships with.

Laravel runs with `APP_ENV=production` and reads no `.env`: that file is ignored
by git, somebody's machine. Its config, route and event caches are pointed away
from `bootstrap/cache`. Symfony runs in `prod` without debug, with `.env` and
`.env.prod`, which are committed as its defaults, and never `.env.local`; its
container is compiled once per run into a directory of the run's own.

The boot is checked against `tests/checks.yaml`, which `specrig surface` writes from
the code: the variables it needs, those it reads with a fallback, and the console
commands that bind a queue. A needed variable answered by neither `.env` nor the
harness fails the boot, named; so does a variable the harness answers that the
code does not read, and a consumer `running()` leaves out. In Laravel every
`env()` has a fallback — null, if no other — so only Symfony has variables a boot
needs: `%env(X)%` with no `default:` processor, `#[Autowire(env: 'X')]`.

A resource takes its own address from its own chain — the address given, then
`<SERVICE>_TEST_POSTGRES` and the like, then a container — so a developer's
database is welcome where the service's settings are not.

## Postgres

```php
public readonly Postgres $database = new Postgres('DATABASE_URL', keep: ['currencies']),
```

The variable receives one URL — Laravel's `DB_URL`, Symfony's `DATABASE_URL` —
with Doctrine's `serverVersion` in it. The address is `address:` if given, else
`<SERVICE>_TEST_POSTGRES`, else a container started through the Docker socket and
kept between runs — starting postgres costs seconds, a database a fraction of one.
The database is the run's own: `shop_test_<pid>`, created at the first boot and
dropped when the run ends. A run killed before its end leaves its database behind,
and the next run drops it: a name of that shape, marked by specrig over an hour
ago, with nobody connected.

The schema is the service's own, built at the first boot of the run: Laravel's
`migrate --force`; Symfony's `doctrine:migrations:migrate`, or the schema from its
mapping where it has no migrations. Before every case every table is truncated but
the migrations' own and those `keep:` names.

`$harness->database->failWith()` points the service's connection at a port where
nothing listens: what connects is refused, as by a database that is down, and the
service meets the driver's own exception. An error given instead fails every
statement with it. A fault a step sets before the boot waits for it, so the
migration goes through; the next case boots whole.

## Redis

```php
public readonly Redis $cache = new Redis('REDIS_URL'),
```

The address is `address:`, else `<SERVICE>_TEST_REDIS`, else a container of the
run's own — starting redis costs less than a second, so nothing is kept — removed
when the run ends. Before every case `FLUSHDB` empties it. `failWith()` gives the
service an address where nothing listens; its clients are built at the boot, so it
is called in a `Given`, before a step reaches the service.

## The records

Both are specrig's format and say so first — `specrig: surface/1`,
`specrig: flows/1` — so that a project's flow map finds the service by them and
reads them beside the others; the protocol's `records.md` is what every writer
agrees on, and its fixtures run here in `FormatTest`. Each is rewritten by what
has its source, and nothing compares it on the way: a project that rewrites them in
CI and then runs `git diff --exit-code` sees a record that fell behind as a diff.

**`tests/surface.yaml`** lists what the code can serve, consume, publish and
call; `vendor/bin/specrig surface` writes it, through
`nikic/php-parser`, from the autoloaded code and, for Laravel, `routes/`,
`config/` and `bootstrap/`:

| | recognised at |
|---|---|
| serves | Laravel: the routes files `withRouting` loads, with `apiPrefix`, `prefix` and `group`, `Route::get` and the other verbs, `match`, `any`, `resource` and `apiResource` with `only` and `except`. Symfony: `#[Route]` on controllers and their classes, invokable ones included, and the YAML routes and imports under `config/routes` |
| consumes | `queue_bind`, by the routing key it binds; a key bound with a wildcard is written as bound — `orders.*` |
| publishes | `basic_publish` and `batch_basic_publish`, by the routing key they name |
| calls | a request through Laravel's `Http` or a `PendingRequest`, Guzzle, or Symfony's `HttpClientInterface` |
| clients | the requests above, by the class that sends them |

A name is followed through constants, interpolation, concatenation, `sprintf` and
the callers of the method that builds it, and one that is not a constant at the
end of that chain is refused: it is a contract, and a contract has a name. A
route keeps its parameters as written, without requirements — `{order<\d+>}` is
`{order}`, `{id?}` is `{id}` — and a varying segment of a request is `{}`; a
request whose whole address comes from data is `GET external`.

What a record cannot learn from the code — the neighbour a client calls — goes
into `specrig.yaml` beside it, with the tool `init` names there for the plugin:

```yaml
guard: [php, vendor/bin/specrig]
flows:
  clients:
    App\Billing: billing
```

**`tests/flows.yaml`** lists what the cases make the service send. Whatever a
`When` step makes reach the service is an input — a request by the route it
matched, an event by its routing key, a job by its class, a command by its name —
and what left the service after it, until the next `When` or `Given`, is what it
sent: a request named by the route of the neighbour's contract that takes it, an
event by its routing key. A refusal is named by its status and what the service
says it is: `HTTP 404 order_not_found` from a problem's `type`; a job that threw
by its exception's class, a command by `exit <code>`.

Every whole run rewrites it, a failing one included; a run narrowed by a path,
`--name` or `--tags` writes nothing, which the harness tells by the scenarios
that ran against the scenarios in `tests/features/`. Only the `description` fields
are written by hand: they survive a rewrite, and one whose step is gone moves to
`orphaned`, comes back with its step, and fails the run meanwhile.

## The protocol

The `specrig.md` that `init` writes holds the people and agents who write the cases
to three steps, with a stop after each: the cases, then approval by a human, then
the code, changed until every case that was red for its own reason is green.
Every edit is made in a mode only the human switches — spec, redgreen or
platform, and redgreen until one is switched — and an edit the mode does not allow
is a stop. Auto mode, which the
human switches on too and nothing offers, runs the whole cycle without a stop for a
human who reviews it all at once.

| mode | may be edited |
|---|---|
| spec | `tests/features/**/*.feature` |
| redgreen | the steps and production code — anything but the cases and the platform's files |
| platform | the platform's files, and production code for a seam |
| auto | `tests/features/**/*.feature`, the steps and production code — anything but the platform's files |

The platform's files are `tests/internal/`, `tests/behat.php`, `specrig.yaml`,
`AGENTS.md` and `CLAUDE.md`; `specrig.md`, `tests/surface.yaml` and
`tests/checks.yaml` are generated and open in no mode. The cases are in
`tests/features/`, not `tests/feature/`: on a case-insensitive filesystem that is
Laravel's `tests/Feature/`, and its PHPUnit tests would be cases. An **area** is a
named set of paths, opened in its own modes or granted by the human on top of
one:

| area | paths | open in | granted in |
|---|---|---|---|
| `composer` | `composer.json`, `composer.lock` | platform | redgreen — a package a step needs is not a platform change |
| `flows` | `tests/flows.yaml` | every mode — the run writes it, a description is written by hand | — |
| `steps` | `tests/steps/` | as the table says | platform — steps that follow a harness change |

`specrig.yaml` declares the service's own under `agents.areas`, and replaces a
default by naming it; a monorepo's shared contract is one:

```yaml
agents:
  areas:
    contract:
      paths: [../../shared/api/]
      modes: [spec, platform]
```

`rules` prints what the tables of `specrig.md` are rendered from — the modes, the
layout and every area's paths — for the service in the working directory, as one
line of JSON, rules/1 of the protocol:

```
vendor/bin/specrig rules
```

The tool is the service's own dev dependency, so the rules move with the service's
version of specrig. In Claude Code the specrig plugin enforces them:

```
/plugin marketplace add specrig/specrig
/plugin install specrig@specrig
```

`/specrig:spec ./path/to/service` switches, `/specrig:allow composer` grants, and
the plugin reads the rules of the tool `specrig.yaml` names once a session and
refuses an edit the mode does not allow before it happens. It runs on the
machine Claude Code runs on, so it needs PHP there and the service's `vendor/`
installed by it; without them the edits are let through, and the plugin says so
once.

## Limits

- Laravel 12 and 13 with `bootstrap/app.php`; Symfony 7.4 and 8 with a kernel
  that is not `final`, since the harness subclasses it.
- A neighbour is stood in for where the framework builds the client. A client the
  service builds itself reads the proxy from the environment — curl, Guzzle and
  Symfony's clients do — and is refused, the case failing on it; PHP's own
  `http://` stream wrapper reads no proxy, so a `file_get_contents` of a URL is not
  caught at all. An `https://` request meets the refusal as a 502 answer or as a
  transport error, as the libcurl under it decides.
- No gRPC either way: a PHP service serves gRPC only under RoadRunner.
- The database is one URL in one variable: Laravel's `DB_URL`, Symfony's
  `DATABASE_URL`. A connection configured host by host is not pointed anywhere.
- php-amqplib is the bus with a stand-in, and its connection has to come from the
  container. A consumer through another client is a command the harness cannot
  drive; Symfony Messenger's AMQP transport is replaced by an in-memory one like
  any async transport, and its messages are named by class, not routing key. A
  headers exchange throws, naming itself.
- `Postgres` and `Redis` are the resources there are; a MySQL one is the same
  shape, not built.
- A Redis fault is set before the boot.
- Behat runs from the service's directory: `vendor/bin/behat -c tests/behat.php`.
- `tests/surface.yaml` reads what the table above names: a request through a
  library it does not, an SDK say, is not in it.

## Development

```bash
make generate
```

```bash
make test
```

The first rewrites the two samples' `tests/surface.yaml`, `tests/checks.yaml` and
`specrig.md` through the tool. The second installs every package with the local Composer and
runs the tests with the local PHP, 8.4 or later: the core's own in `tests/` — the
records, the contract check, the stand-ins, the proxy, the protocol's record and
agents fixtures, `rules`, `surface` and `init` over the apps in `tests/fixtures/laravel` and
`tests/fixtures/symfony` —, each harness's own against its sample, then the suites
of the two samples in `samples/`, which rewrite their `tests/flows.yaml`. The
resources start in Docker. `make test-docker` and `make generate-docker` do the
same in the image the shop sample builds, given the host's Docker socket.

```bash
docker rm -f $(docker ps -aq --filter label=specrig=postgres)
```

removes the kept Postgres container.

```bash
make protocol
```

refreshes the copies of the protocol from a checkout of it beside this one,
`PROTOCOL` to name another; the tests then run against the new copy.

## Releasing

The packages are developed here and published from a read-only mirror each, since
Packagist takes a package from the root of a repository:

| package | from | mirror |
|---|---|---|
| `specrig/specrig` | `packages/specrig` | `specrig/specrig-php-core` |
| `specrig/laravel` | `packages/laravel` | `specrig/specrig-php-laravel` |
| `specrig/symfony` | `packages/symfony` | `specrig/specrig-php-symfony` |

On every push to `main` and every tag `v*`, `.github/workflows/release.yml` runs
`make generate` and `make test`, fails when they leave a diff, and then pushes each
package's history, split out with `git subtree split`, to its mirror: `main` to its
`main`, a tag to the same tag. A version is one tag here:

```bash
git tag v0.1.0
```

```bash
git push origin v0.1.0
```

All three mirrors get it, and they move together: an adapter requires the core of
its own version, `self.version`. An adapter's archive leaves out its `tests/` and
`phpunit.xml`, which run only against the samples here. Issues and changes come
here; a mirror takes only the workflow's pushes, and a commit made in one stops
the next push to it.

Once, before the first push that is to reach the mirrors:

1. the three mirrors, created empty on GitHub;
2. a fine-grained token with read and write access to the contents of the three,
   saved in this repository as the secret `SPLIT_TOKEN`;
3. after the first run, each mirror submitted to Packagist, with its update hook,
   so a push to the mirror reaches Packagist.
