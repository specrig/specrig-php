<?php

use App\Http\Problem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Client\ConnectionException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(fn (QueryException $e) => Problem::of(503, 'database_unavailable', 'the shop cannot reach its orders'));
        $exceptions->render(fn (ConnectionException $e) => Problem::of(503, 'billing_unavailable', 'the shop cannot reach billing'));
    })
    ->create();
