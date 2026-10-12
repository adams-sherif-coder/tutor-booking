<?php

use App\Http\Middleware\CheckRole;
use App\Http\Middleware\EnsureActive;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['role' => CheckRole::class, 'active' => EnsureActive::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request): bool => $request->is('api/*') || $request->expectsJson());
        $exceptions->respond(function (Response $response): Response {
            if (request()->is('api/*') && $response->getStatusCode() >= 400) {
                $body = json_decode($response->getContent(), true) ?? [];
                $status = $response->getStatusCode();
                $payload = ['data' => [], 'message' => $status >= 500 ? 'An unexpected server error occurred.' : ($body['message'] ?? Response::$statusTexts[$status]), 'status' => $status, 'ok' => false];
                if (isset($body['errors'])) {
                    $payload['errors'] = $body['errors'];
                }
                $response->setContent(json_encode($payload));
                $response->headers->set('Content-Type', 'application/json');
            }

            return $response;
        });
    })->create();
