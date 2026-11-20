<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Services\Core\PeriodService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PeriodController extends Controller
{
    public function __construct(private PeriodService $periods) {}

    public function index(): JsonResponse
    {
        return ApiResponse::ok($this->periods->list());
    }

    /** The closing screen (§9.8): every month with its status and, for open months, the checklist and its counts. */
    public function overview(): JsonResponse
    {
        return ApiResponse::ok($this->periods->overview());
    }

    /** Pre-close checks of a month (`YYYY-MM`): blockers stop the close, warnings do not. */
    public function checks(string $month): JsonResponse
    {
        return ApiResponse::ok($this->periods->checks(...$this->split($month)));
    }

    public function close(Request $request, string $month): JsonResponse
    {
        $result = $this->periods->close(...[...$this->split($month), $request->user()]);

        return ApiResponse::ok([...$result['period']->toArray(), 'warnings' => $result['warnings']]);
    }

    public function reopen(Request $request, string $month): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return ApiResponse::ok($this->periods->reopen(...[...$this->split($month), $data['reason'], $request->user()]));
    }

    /** @return array{0: int, 1: int} */
    private function split(string $month): array
    {
        return [(int) substr($month, 0, 4), (int) substr($month, 5, 2)];
    }
}
