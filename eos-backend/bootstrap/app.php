<?php

use App\Modules\Identity\Http\Middleware\RequirePermission;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Private channel auth for the tasks boards: POST /api/broadcasting/auth with the Sanctum token.
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']])
    ->withMiddleware(function (Middleware $middleware): void {
        // Check `perm:` before route-model binding, so a caller without the permission gets 403 for every id
        // instead of learning from 404/422 which records exist.
        $middleware->prependToPriorityList(SubstituteBindings::class, RequirePermission::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e) => $request->is('api/*') || $request->expectsJson()
        );

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'المورد المطلوب غير موجود.'], 404);
            }
        });

        // A conflict that outlived the transaction retries (deadlock, MariaDB 1020, serialization failure) is
        // a busy moment, not a server fault: ask the user to repeat the action instead of a 500.
        $exceptions->render(function (QueryException $e, Request $request) {
            if ($request->is('api/*') && app(ConcurrencyErrorDetector::class)->causedByConcurrencyError($e)) {
                return response()->json(['message' => 'تزامنت العملية مع عملية أخرى على البيانات نفسها؛ أعد المحاولة.'], 409);
            }
        });

        // Two requests creating the same unique record at once: validation passed for both, the database refused
        // the second. That is a conflict for the client to refresh, not a server fault.
        $exceptions->render(function (UniqueConstraintViolationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'يوجد سجل بالقيم نفسها مسبقاً؛ حدّث الصفحة وأعد المحاولة.'], 409);
            }
        });

        // A row still referenced elsewhere (foreign key) or a parent that vanished between check and write: a conflict
        // with other records, not a server fault.
        $exceptions->render(function (QueryException $e, Request $request) {
            $driverCode = (int) ($e->errorInfo[1] ?? 0);
            if ($request->is('api/*') && ((string) ($e->errorInfo[0] ?? '') === '23000' || (string) ($e->errorInfo[0] ?? '') === '23503')
                && (in_array($driverCode, [1451, 1452], true) || str_contains($e->getMessage(), 'FOREIGN KEY constraint failed'))) {
                return response()->json(['message' => 'العملية تتعارض مع سجلات مرتبطة بها؛ حدّث الصفحة وأعد المحاولة.'], 409);
            }
        });

        // Last line of defence for values validation let through but the column cannot hold (MariaDB strict mode:
        // 22001 data too long, 22003 numeric out of range, 22007 invalid date): answer 422, never 500.
        $exceptions->render(function (QueryException $e, Request $request) {
            if ($request->is('api/*') && in_array((string) ($e->errorInfo[0] ?? $e->getCode()), ['22001', '22003', '22007'], true)) {
                return response()->json(['message' => 'قيمة مدخلة خارج الحدود المسموحة؛ راجع الأرقام والنصوص الطويلة.'], 422);
            }
        });
    })->create();
