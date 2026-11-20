<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    public function attempt(string $email, string $password): ?User
    {
        $user = User::where('email', $email)->first();
        if ($user === null) {
            // Spend the same time as a real check so response timing does not reveal which emails exist.
            Hash::check($password, '$2y$12$Hd3c8Lv64YvsP5tZ6bT3UuBvSr730z2c4UvsP/jjnuq2.TVIDzZiG');

            return null;
        }

        return Hash::check($password, $user->password) ? $user : null;
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
