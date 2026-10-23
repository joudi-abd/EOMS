<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Http\Requests\StoreUserRequest;
use App\Modules\Identity\Http\Requests\UpdateUserRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\UserService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class UserController extends Controller
{
    public function __construct(private UserService $users) {}

    public function index(Request $request)
    {
        $this->ensureCan($request);

        return ApiResponse::paginated(
            $this->users->search($request->query())->through(fn (User $user) => new UserResource($user))
        );
    }

    public function store(StoreUserRequest $request)
    {
        $this->ensureCan($request);

        return ApiResponse::created(new UserResource($this->users->create($request->validated())));
    }

    public function show(Request $request, User $user)
    {
        $this->ensureCan($request);

        return ApiResponse::ok(new UserResource($user));
    }

    public function update(UpdateUserRequest $request, User $user)
    {
        $this->ensureCan($request);

        return ApiResponse::ok(new UserResource($this->users->update($user, $request->validated())));
    }

    public function updateStatus(Request $request, User $user)
    {
        $this->ensureCan($request);

        $data = $request->validate([
            'status' => ['required', 'in:active,disabled'],
        ]);

        return ApiResponse::ok(new UserResource($this->users->setStatus($user, $data['status'], $request->user())));
    }

    public function assignRoles(Request $request, User $user)
    {
        $this->ensureCan($request);

        abort_unless(
            $request->user()->can('assignRoles', $user),
            403,
            'فقط مدير النظام يستطيع تغيير الأدوار.'
        );

        $data = $request->validate([
            'roles' => ['required', 'array'],
            'roles.*' => ['string', 'exists:usr_roles,name'],
        ]);

        return ApiResponse::ok(new UserResource($this->users->assignRoles($user, $data['roles'])));
    }

    private function ensureCan(Request $request): void
    {
        abort_unless($request->user()?->can('users.manage'), 403, 'ليست لديك صلاحية إدارة المستخدمين.');
    }
}