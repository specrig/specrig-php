# Design

What specrig for PHP is built from, where it follows its siblings and where it
cannot, what is left for the owner to decide, and the order it was built in.

## Where it sits

| | for | how it reaches the service | records |
|---|---|---|---|
| specrig-js | a web app | a browser, Playwright | the app's requests to its backends |
| specrig-dotnet | a .NET service on the Generic Host | the host `Program` builds, caught as it is built | inputs over HTTP, gRPC, events, jobs and commands, and what the service sends on |
| specrig-php | a Laravel or Symfony service | the application its own bootstrap builds, in the suite's process | the .NET kind, without gRPC |

It is specrig-dotnet's shape on PHP's ground. A Laravel application and a
Symfony kernel are what `Program` and the Generic Host are in .NET: the service
as it ships, built by its own code. Laravel's own tests and Symfony's
`KernelTestCase` already boot them in a test process; the harness does the same,
once per case, and puts the stand-ins in at the seams each framework has.

## One request at a time

PHP runs a request to its end on one thread, and so does a Behat run: a step
that sends a request returns when the service has answered it and done
everything the request made it do. Three things follow.

- **Nothing settles.** .NET waits after a step for calls still in flight; here
  there are none.
- **A neighbour is not held.** A hold released by a later step would wait inside
  the step that sent the request, forever. `timeOut(method, path)` takes its
  place: the client sees the timeout it would see, at once.
- **Background work is a step.** A queued job, a Messenger message on an async
  transport, a consumer's loop: none runs by itself. `work()` runs what the
  service queued, as its worker would; `deliver()` hands an event to the
  consumer the service registered.

## The pieces and what they are built on

| piece | Laravel | Symfony |
|---|---|---|
| boot | `bootstrap/app.php`, bootstrapped by the console kernel, as Laravel's `TestCase` does; one application per case | the service's `Kernel`, subclassed to compile a container of the run's own in a temporary directory, with the harness's compiler passes; one kernel per case |
| environment | `APP_ENV=production`, `APP_DEBUG=false`; no `.env` read — it is a developer's file; config, route and event caches pointed away | `APP_ENV=prod`, debug off; `.env` and `.env.prod` read, never `.env.local` |
| settings | environment variables, set before the boot: the harness's answers, and every other variable the code reads unset | the same; `.env` answers what the harness does not |
| inbound HTTP | the HTTP kernel, `handle` and `terminate`; named by `Route::uri()` | the HTTP kernel, `handle` and `terminate`; named by the matched route's path |
| outbound HTTP | `Http::fake` with one callback that routes by origin to the stand-ins | `framework.http_client.mock_response_factory` set to a responder that routes by origin to the stand-ins, so the transport under every scoped client and decorator is the framework's own `MockHttpClient` |
| a request past them | the harness's proxy: `http_proxy`, `https_proxy` and `all_proxy` point at a process of the run's own that refuses every request and writes it down, so a client the service builds itself is refused before anything leaves | the same |
| a job | `run(Job::class)` from the container, by `handle`, `__invoke`, `run` or `execute`, with the container's method injection; a queued job instance runs as the worker runs it, serialised and fired | `run(Service::class)` from the test container; a message instance is dispatched with a `ReceivedStamp`, as a worker hands it on |
| what the service queues | a queue connection of the harness's own, in place of every configured one, which keeps the payloads; `work()` fires them | every async transport replaced by an in-memory one that serialises; `work()` consumes them |
| a command | the console kernel, `artisan` as it runs | the console `Application`, `bin/console` as it runs |
| the bus | `php-amqplib`: a connection of the harness's own in the container's place; consumers are the commands `running()` names, run at the boot until they wait | the same |
| clock | `Carbon::setTestNow`, from the step that sets it | the `clock` service and `Clock::set`, and Carbon's where it is installed |
| logs | `MessageLogged` | the `logger` service decorated |
| grants | `replace(Type::class, $instance)`: `instance()` after the providers register | `replace(Type::class, $instance)`: every granted service decorated by one that hands out the replacement |
| Postgres | `migrate --force` once per run; every table but `migrations` and `keep()` put back before every case as the migrations left it; a refusal by the connection's URL pointed at a closed port and the connection purged; another error by `beforeExecuting`; `heal` points the URL back and purges | `doctrine:migrations:migrate` once per run, else the schema created; every table but `doctrine_migration_versions` and `keep()` put back before every case as the migrations left it; a DBAL middleware refuses connecting, or fails every statement with the step's error; `heal` clears the middleware's fault |
| a table's writes | triggers in Postgres itself, in a schema of their own, put there by `refuse`, `refuseAfter` and `refuseCommit`; dropped by `heal` and before every case | the same |
| Redis | the address the service gets is a gate's, a process of the run's own that forwards; `failWith` closes it; `FLUSHDB` before every case | the same |
| steps | Behat: an extension hands every context the case's harness, marks Given, When and Then, fails a case on what the harness found, and writes `tests/flows.yaml` after a whole run | |
| `tests/surface.yaml` | `nikic/php-parser` over `app/`, `routes/`, `config/` and `bootstrap/` | the same over `src/` and `config/`, routes from attributes and `config/routes` |
| containers | the Docker Engine API over its socket, through curl, from the suite's own process: no Docker CLI | |

## The format is not PHP's to change

A flow map joins services by the names in their records. The PHP writer
writes them as the protocol's `records.md` says, and runs the protocol's record
fixtures, copied into `tests/fixtures/protocol/records/`, in `FormatTest`.

| what | name | PHP writes it from |
|---|---|---|
| an HTTP route served | `GET /orders/{id}` | Laravel's `Route::uri()` with `{id?}` as `{id}`; Symfony's route path, inline requirements and defaults dropped; a route of no method is `*` |
| an HTTP request sent | `GET /invoices/{orderId}` in `flows`, `GET /invoices/{}` in `surface` | the neighbour's contract template; the code through `php-parser` |
| an event | `invoices.paid` | the routing key of `basic_publish` and `queue_bind` |
| a command | `reminders:send` | the console command's name |
| a job | `SendReminders` | the job's, or the message's, class without its namespace |
| an HTTP refusal | `HTTP 404 order_not_found` | status and the body's `type`, dropped when it is a URI |
| a job or command that failed | `RuntimeException`, `exit 1` | the exception's class without its namespace; a non-zero exit code, as specrig-dotnet writes them |

## Decisions taken, and why

**Behat.** It is the Gherkin runner PHP has; the extension API lets the harness
hook a case without a context of its own in the suite. Behat 4 reads its
configuration from PHP alone, and 3.34 reads PHP too, so `tests/behat.php` serves
both.
The extension hands contexts the harness through an argument resolver, adds every
class in `tests/steps/` to every suite through a suite generator, marks the steps
and writes the record through a listener, and wraps the scenario tester: Behat
counts a failed `AfterScenario` hook apart from the scenario, and a case whose
harness found an unanswered call would pass.

**`webmozart/assert`.** PHPUnit's assertions need PHPUnit's runner to fail with a
message; outside it they do not.

**`tests/features/`.** `tests/feature/` is Laravel's `tests/Feature/` on a
case-insensitive filesystem, and its PHPUnit tests would sit among the cases.

**The surface pass is `nikic/php-parser`.** No reflection: the code is read, not
loaded, so `specrig surface` needs no database and boots nothing. A name is followed
through constants, interpolation, concatenation, `sprintf` and the callers of the
method that builds it, with as much type inference as the declarations give.

**The protocol is copied, not reimplemented.** `modes.yaml` and the parts of
`specrig.md` come from the protocol, and its agents fixtures run in `AgentsTest`;
`make protocol` refreshes them. The guard fixtures are the plugin's: this stack
prints its rules, and the plugin checks against them.

**The suite runs on the developer's PHP.** The suite is `vendor/bin/behat`, as
every specrig stack runs its native tool: a PHP developer has PHP and Composer,
and runs and debugs the cases from an IDE. Only what the service depends on runs
in Docker. The modes are the other reason: the specrig plugin runs
`php vendor/bin/specrig rules` on the host, and a `vendor/` that lived only in a
container would leave them unenforced. `tests/internal/Dockerfile` keeps an
image for CI and machines without PHP; how a project runs it is the project's.

**One application per case.** Laravel's and Symfony's tests do it for the same
reason .NET does: a singleton that keeps state is a leak between cases. Booting a
Laravel application costs milliseconds; a Symfony kernel with a compiled
container about as much, and the container is compiled once per run.

**Back as the migrations left it, not empty.** A schema without the rows its
migrations write — the states an order moves through, the root of a tree — exists
in no environment; a step that writes them again is a second copy of the
migration that drifts from the first, and a `keep()` for them carries one case's
changes into the next. Writing them back turns foreign keys and triggers off for
one transaction, which takes a superuser; migrations that write no rows need none.

**A container of the run's own.** A Symfony container compiled without debug
never checks its sources, so one kept between runs would serve yesterday's code.
Compiling costs a second or two per run.

**No `.env` in Laravel.** Laravel's `.env` is ignored by git: it is somebody's
machine, as `appsettings.Development.json` is. The defaults `config/*.php` gives
are what ships. Symfony commits `.env` as its defaults and keeps the machine in
`.env.local`, so there `.env` is read and `.env.local` is not.

**Every setting read is answered or unset.** A variable the code reads and the
harness does not answer is removed from the environment before the boot, so a
developer's own `BILLING_URL` cannot send a case to the real billing, and the
service sees the default its code ships with. `env('X')` without a default is
optional in Laravel — null is its declared fallback — so only Symfony has
settings a boot can lack: `%env(X)%` with no `default:` processor and no line in
`.env`.

**A proxy, not a listener.** .NET learns of a request past the stand-ins after it
left. PHP's clients read the proxy from the environment — curl does, Guzzle and
Symfony's clients do — so the harness's own refuses it instead, and the request
never leaves.

**A timeout, not a hold.** See above.

**A gate for Redis.** A Redis client is built at the boot and keeps its connection:
the Laravel manager's, Symfony's cache pools, Messenger's transport and a client the
service built by hand each hold their own, and there is no one place a fault could be
set in them. So the service is given the address of a gate, which forwards to the
server, and a fault closes the gate: what is connected is cut, what connects is
refused, whichever client it is and whenever it was built. PHP runs one request at a
time, so the gate cannot be a thread of the suite's process; it is a process of the
run's own, as the proxy is, and the suite commands it over its stdin — no signals,
which need `pcntl`, and no second port. It ends with the suite: a closed stdin ends
it. The fault waits for the boot, as the database's does, so the service boots with
its Redis up and loses it after.

**Refusals in the database, not in the driver.** A table's writes are refused by
triggers in Postgres itself, so they hold for every connection — the case's own, the
service's, a worker's — whatever client library it uses. They are gorib's `sqltest`
ported: a schema of their own, outside what discovery and the baseline read, dropped
whole by `heal` and by the reset before every case. Only `heal` differs by framework:
Laravel points the connection's URL back and purges it, Symfony clears the DBAL
middleware's fault.

**Replies by template.** A reply, a time-out and a read of the requests name a path
as the neighbour's contract does: a segment in braces takes any one segment. The
exact path wins over a template, and of two templates the first declared does, as in
specrig-js. The contract check and the trace name a request by its real path.

**`.feature` files in their own language.** The whole-run check counts the scenarios
in `tests/features/` by the keywords of each file's `# language:` directive, from
behat/gherkin's own dialects, so a file in Russian is counted and `tests/flows.yaml`
is written.

**php-amqplib first.** It is the client a PHP service uses to speak JSON over
RabbitMQ with services in other languages, and what most Laravel and Symfony
RabbitMQ packages are built on. Symfony Messenger's AMQP transport needs the
`amqp` extension and names a message by its class; a transport another service
consumes is an adapter of its own, later.

**The consumers run until they wait.** A php-amqplib consumer is a long-running
command: it declares, binds, `basic_consume`s and loops on `wait()`. The stand-in
says the channel is not consuming, so the loop ends at once with the consumer
kept, and `deliver()` calls its callback with a message whose `ack`, `nack` and
`reject` settle it.

## The owner's calls

1. **PHP and the frameworks.** PHP 8.2 and up, Laravel 12 and 13, Symfony 7.4
   and 8.
2. **Behat.** 3.34 and up, and 4.
3. **gRPC.** Neither way: a PHP service serves gRPC only under RoadRunner, and a
   client stand-in under the `grpc` extension is not built.
4. **MySQL.** Postgres and Redis are the resources there are; a MySQL resource is
   the same shape.
5. **Enforcement in Claude Code.** As in .NET: `rules` prints the service's rules,
   and the plugin checks every edit against them.

## Order, and where it stands

| phase | delivers | stands |
|---|---|---|
| 0 | the records and the fixtures | built |
| 1 | boot, settings, HTTP stand-ins and the contract check, the Behat binding, Postgres, logs, the clock; HTTP, jobs and commands as ways in; `tests/flows.yaml` | built |
| 2 | `tests/surface.yaml` and `tests/checks.yaml`, `init`, `rules`, `specrig.md` | built |
| 3 | the bus, `work()`, Redis and its gate, grants, `failWith`, the refusals and `heal` | built |
