<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Identity\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AuditLogController extends Controller
{
    /** Audit trail of finance, exchange rates and settings (T23), newest first. */
    public function index(Request $request, AuditLogger $audit): JsonResponse
    {
        $filters = $request->validate([
            'action' => ['sometimes', 'string', 'max:100'],
            'user_id' => ['sometimes', 'integer'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'auditable_type' => ['sometimes', 'string', 'max:100'],
            'auditable_id' => ['sometimes', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return ApiResponse::paginated($audit->search(['finance.', 'fx.', 'settings.'], $filters, (int) ($filters['per_page'] ?? 50)));
    }
}
