<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Http\Requests\StoreUserRequest;
use App\Modules\Identity\Http\Requests\UpdateUserRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class UserController extends Controller
{
    public function __construct(private UserService $users) {}

    public function index(Request $request)
    {
        $this->ensureCan($request);

        $search = trim((string) $request->query('q', ''));

        $users = User::query()
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->orderBy('name')
            ->paginate(20);

        return UserResource::collection($users);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $this->ensureCan($request);

        $user = $this->users->create($request->validated());

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    public function show(Request $request, User $user)
    {
        $this->ensureCan($request);

        return new UserResource($user);
    }

    public function update(UpdateUserRequest $request, User $user)
    {
        $this->ensureCan($request);

        return new UserResource($this->users->update($user, $request->validated()));
    }

    public function updateStatus(Request $request, User $user)
    {
        $this->ensureCan($request);

        $data = $request->validate([
            'status' => ['required', 'in:active,disabled'],
        ]);

        return new UserResource($this->users->setStatus($user, $data['status'], $request->user()));
    }

    private function ensureCan(Request $request): void
    {
        abort_unless($request->user()?->can('users.manage'), 403, 'ليست لديك صلاحية إدارة المستخدمين.');
    }

    public function assignRoles(Request $request, User $user)
    {
        $this->ensureCan($request);

        // نستخدم $request->user() مباشرة لأن Sanctum لا يُمرّر المستخدم للحارس الافتراضي
        abort_unless(
            $request->user()->can('assignRoles', $user),
            403,
            'فقط مدير النظام يستطيع تغيير الأدوار.'
        );

        $data = $request->validate([
            'roles' => ['required', 'array'],
            'roles.*' => ['string', 'exists:usr_roles,name'],
        ]);

        return new UserResource($this->users->assignRoles($user, $data['roles']));
    }
}