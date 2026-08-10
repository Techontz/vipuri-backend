<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'user.active' => \App\Http\Middleware\EnsureUserIsActive::class,
            'admin.active' => \App\Http\Middleware\EnsureAdminIsActive::class,
            'shop.open' => \App\Http\Middleware\EnsureNotInMaintenance::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
        ]);

        $middleware->throttleApi();

        // VIPURI has no server-rendered login page. Without this, Laravel's
        // Authenticate middleware tries to redirect an unauthenticated
        // non-JSON request to route('login') and dies with a 500 instead of
        // returning a clean 401.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        // Every API failure uses the same envelope as every success, so the
        // Next.js client only ever has to understand one shape.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            if ($e instanceof ValidationException) {
                return response()->json([
                    'remark' => 'validation_error',
                    'status' => 'error',
                    'message' => ['error' => collect($e->errors())->flatten()->all()],
                    'errors' => $e->errors(),
                ], 422);
            }

            if ($e instanceof AuthenticationException) {
                return response()->json([
                    'remark' => 'unauthenticated',
                    'status' => 'error',
                    'message' => ['error' => ['Unauthorized request']],
                ], 401);
            }

            if ($e instanceof AuthorizationException || $e instanceof \Spatie\Permission\Exceptions\UnauthorizedException) {
                return response()->json([
                    'remark' => 'forbidden',
                    'status' => 'error',
                    'message' => ['error' => ['You do not have permission to perform this action']],
                ], 403);
            }

            if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
                return response()->json([
                    'remark' => 'not_found',
                    'status' => 'error',
                    'message' => ['error' => ['The requested resource was not found']],
                ], 404);
            }

            if ($e instanceof RuntimeException && ! $e instanceof HttpExceptionInterface) {
                return response()->json([
                    'remark' => 'error',
                    'status' => 'error',
                    'message' => ['error' => [$e->getMessage()]],
                ], 422);
            }

            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

            return response()->json([
                'remark' => 'server_error',
                'status' => 'error',
                'message' => ['error' => [
                    config('app.debug') ? $e->getMessage() : 'Something went wrong. Please try again.',
                ]],
            ], $status);
        });
    })->create();
