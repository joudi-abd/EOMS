<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** The signed-in user's own in-app notifications. */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['unread' => ['sometimes', 'boolean'], 'per_page' => ['sometimes', 'integer', 'between:1,100']]);
        $query = ($data['unread'] ?? false) ? $request->user()->unreadNotifications() : $request->user()->notifications();
        $paginator = $query->paginate($data['per_page'] ?? 20);
        $paginator->setCollection($paginator->getCollection()->map(fn ($n) => [
            'id' => $n->id, 'kind' => $n->data['kind'] ?? null, 'message' => $n->data['message'] ?? null,
            'data' => $n->data, 'read_at' => $n->read_at?->toIso8601String(), 'created_at' => $n->created_at?->toIso8601String(),
        ]));
        $body = ApiResponse::paginated($paginator)->getData(true);
        $body['unread_count'] = $request->user()->unreadNotifications()->count();

        return response()->json($body);
    }

    public function read(Request $request, string $id): JsonResponse
    {
        $request->user()->notifications()->whereKey($id)->firstOrFail()->markAsRead();

        return ApiResponse::message('تم.');
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return ApiResponse::message('تم.');
    }
}
