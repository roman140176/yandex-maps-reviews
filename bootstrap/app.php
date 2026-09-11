<?php

use App\Enums\ParseTrigger;
use App\Services\Parsing\Exceptions\ParsingException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The SPA lives on the same origin, so API calls carry the session
        // cookie and CSRF protection stays on.
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Parsing failures are expected outcomes, not crashes: the client gets
        // the machine code so it can react, plus a message fit to display.
        $exceptions->render(function (ParsingException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode(),
                'retryable' => $e->isRetryable(),
            ], 422);
        });
    })
    ->withSchedule(function ($schedule): void {
        if (config('parsing.refresh.enabled')) {
            $schedule->command('organizations:parse --all --trigger='.ParseTrigger::Scheduled->value)
                ->cron('0 */'.max(1, (int) config('parsing.refresh.interval_hours')).' * * *')
                ->withoutOverlapping();
        }
    })
    ->create();
