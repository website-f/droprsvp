<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // Payment gateway webhooks are server-to-server (no CSRF token).
        $middleware->validateCsrfTokens(except: ['webhooks/*']);

        // Spatie role/permission middleware aliases (used to gate the CMS to superadmin).
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // When a session times out, protected routes bounce guests to /login.
        // Flash a message so the frontend can toast "your session expired" instead
        // of silently dumping the user on the login page.
        $middleware->redirectGuestsTo(function (Request $request) {
            if (! $request->expectsJson()) {
                $request->session()->flash('flash_warning', 'Your session has expired — please sign in again.');
            }

            return route('login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A stale CSRF token (419) after the session expires — send the user to
        // log in again with the same friendly notice rather than a blank error page.
        $exceptions->render(function (\Illuminate\Session\TokenMismatchException $e, Request $request) {
            if ($request->expectsJson()) {
                return null;
            }
            $request->session()->flash('flash_warning', 'Your session has expired — please sign in again.');

            return redirect()->route('login');
        });
    })->create();
