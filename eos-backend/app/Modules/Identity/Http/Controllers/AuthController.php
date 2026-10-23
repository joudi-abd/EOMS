<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Services\AuthService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AuthController extends Controller
{
    public function __construct(private AuthService $auth) {}

    public function login(LoginRequest $request)
    {
        $data = $request->validated();

        $user = $this->auth->attempt($data['email'], $data['password']);

        // رسالة واحدة للبريد وكلمة المرور حتى لا نكشف وجود الحسابات
        if (! $user) {
            return ApiResponse::message('بيانات الدخول غير صحيحة.', 401);
        }

        if ($user->status !== 'active') {
            return ApiResponse::message('هذا الحساب معطّل.', 403);
        }

        return ApiResponse::ok([
            'token' => $this->auth->issueToken($user),
            'user' => new UserResource($user),
        ]);
    }

    public function logout(Request $request)
    {
        $this->auth->revoke($request->user());

        return ApiResponse::message('تم تسجيل الخروج.');
    }

    public function me(Request $request)
    {
        return ApiResponse::ok(['user' => new UserResource($request->user())]);
    }
}