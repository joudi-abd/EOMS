<?php

namespace App\Modules\HR\Http\Controllers;

use App\Modules\HR\Services\Attendance\AttendanceImportService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

class AttendanceIngestController extends Controller
{
    /** Trusted bridge JSON endpoint; NOT the native iFace/ADMS protocol. */
    public function store(Request $request, AttendanceImportService $importer): JsonResponse
    {
        $data = $request->validate([
            'events' => ['present', 'array', 'max:500'],
            'events.*' => ['required', 'array:external_id,device_user_id,occurred_at,direction'],
            'events.*.external_id' => ['required', 'string', 'max:120'],
            'events.*.device_user_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/D'],
            'events.*.occurred_at' => ['required', 'string', 'max:40'],
            'events.*.direction' => ['sometimes', Rule::in(['in', 'out', 'unknown'])],
            'complete_through' => ['nullable', 'string', 'max:40'],
        ]);

        return ApiResponse::ok($importer->import($request->attributes->get('attendance_device'), $data['events'], $data['complete_through'] ?? null));
    }
}
