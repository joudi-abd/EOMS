<?php

namespace App\Modules\HR\Http\Middleware;

use App\Modules\HR\Models\AttendanceDevice;
use Closure;
use Illuminate\Http\Request;

class AuthenticateAttendanceDevice
{
    public function handle(Request $request, Closure $next): mixed
    {
        abort_unless($request->isSecure() || app()->environment(['local', 'testing']), 403, 'HTTPS مطلوب.');
        $token = $request->bearerToken();
        abort_unless(is_string($token) && strlen($token) === 64, 401, 'اعتماد الجهاز غير صالح.');
        $device = AttendanceDevice::query()->where('token_hash', hash('sha256', $token))->first();
        abort_unless($device && $device->is_active, 401, 'اعتماد الجهاز غير صالح.');
        $request->attributes->set('attendance_device', $device);

        return $next($request);
    }
}
