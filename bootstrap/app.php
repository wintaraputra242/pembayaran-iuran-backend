<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;
use App\Http\Middleware\ForceJsonResponse;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Helpers\ApiResponse;
use App\Http\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => RoleMiddleware::class,
        ]);

        // hapus block web() ini sepenuhnya
        // $middleware->web(append: [
        //     \App\Http\Middleware\VerifyCsrfToken::class,
        // ]);

        // $middleware->api([
        //     \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        // ]);

        $middleware->append(
            \Illuminate\Http\Middleware\HandleCors::class
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // 🔒 Token Sanctum tidak valid / belum login
        $exceptions->render(function (AuthenticationException $e, $request) {
            return ApiResponse::error('Tidak terautentikasi. Token tidak valid atau sudah kadaluarsa.', null, 401);
        });

        // 🧾 Error validasi
        $exceptions->render(function (ValidationException $e, $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error('Validasi gagal.', $e->errors(), 422);
            }
        });

        // ❌ Endpoint tidak ditemukan
        $exceptions->render(function (NotFoundHttpException $e, $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error('Endpoint tidak ditemukan.', null, 404);
            }
        });

        // ⚠️ Error umum lain (fallback)
        $exceptions->render(function (Throwable $e, $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    app()->hasDebugModeEnabled()
                        ? $e->getMessage()
                        : 'Terjadi kesalahan pada server.',
                    null,
                    method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500
                );
            }
        });
    })

    ->withSchedule(function (Schedule $schedule) {
        $schedule->command('warga:delete-inactive')->daily();
        $schedule->command('informasi-iuran:delete-inactive')->daily();
    })
    ->create();
