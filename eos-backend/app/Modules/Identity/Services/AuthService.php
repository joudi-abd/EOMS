<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    public function attempt(string $email, string $password): ?User
    {
        $user = User::where('email', $email)->first();

        return ($user && Hash::check($password, $user->password)) ? $user : null;
    }

    public function issueToken(User $user): string
    {
        $user->forceFill(['last_login_at' => now()])->save();

        return $user->createToken('api')->plainTextToken;
    }

    public function revoke(User $user): void
    {
        $user->currentAccessToken()?->delete();
    }
}