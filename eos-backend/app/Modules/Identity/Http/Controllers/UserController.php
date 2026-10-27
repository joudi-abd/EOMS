<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Http\Requests\StoreUserRequest;
use App\Modules\Identity\Http\Requests\UpdateUserRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\UserService;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

#[Group('02 - User Management', weight: 2)]
class UserController extends Controller
{
    public function __construct(private UserService $users) {}

    /**
     * List users.
     *
     * Returns a paginated user list.
     * Supports searching and filtering.
     */
    public function index(Request $request)
    {
        $this->ensureCan($request);

        return ApiResponse::paginated(
            $this->users->search($request->query())->through(fn (User $user) => new UserResource($user))
        );
    }

    /**
     * Create a user.
     *
     * Creates a user record.
     */
    public function store(StoreUserRequest $request)
    {
        $this->ensureCan($request);

        return ApiResponse::created(new UserResource($this->users->create($request->validated())));
    }

    /**
     * Show user details.
     *
     * Returns the user profile.
     */
    public function show(Request $request, User $user)
    {
        $this->ensureCan($request);

        return ApiResponse::ok(new UserResource($user));
    }

    /**
     * Update user details.
     *
     * Updates the user profile information.
     */
    public function update(UpdateUserRequest $request, User $user)
    {
        $this->ensureCan($request);

        return ApiResponse::ok(new UserResource($this->users->update($user, $request->validated())));
    }

    /**
     * Update user status.
     *
     * Updates the user's account status.
     */
    public function updateStatus(Request $request, User $user)
    {
        $this->ensureCan($request);

        $data = $request->validate([
            'status' => ['required', 'in:active,disabled'],
        ]);

        return ApiResponse::ok(new UserResource($this->users->setStatus($user, $data['status'], $request->user())));
    }

    /**
     * Assign roles to a user.
     *
     * Updates the user's assigned roles.
     */
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
