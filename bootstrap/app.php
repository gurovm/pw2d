<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\InitializeTenancyIfApplicable::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Auto-refresh on expired CSRF token (419) instead of showing a dead-end page
        $exceptions->respond(function (\Symfony\Component\HttpFoundation\Response $response) {
            if ($response->getStatusCode() === 419) {
                return redirect()->back();
            }
            return $response;
        });
    })->create();
