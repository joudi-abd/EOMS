<?php

namespace App\Modules\HR\Http\Middleware;

use App\Modules\HR\Models\AttendanceDevice;
use App\Modules\HR\Services\Attendance\AdmsDiagnostics;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAdmsDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('attendance.adms.enabled')) {
            AdmsDiagnostics::record($request, 503, 'adms_disabled');

            return response('ADMS DISABLED', 503, ['Content-Type' => 'text/plain']);
        }
        // Use the actual socket peer; never trust client-provided X-Forwarded-For.
        // A reverse proxy must enforce the device allowlist and preserve source IP.
        $peer = (string) $request->server('REMOTE_ADDR');
        $serial = $request->query('SN', $request->query('sn'));
        if (! is_string($serial) || ! preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $serial)) {
            AdmsDiagnostics::record($request, 403, 'invalid_serial');

            return response('FORBIDDEN', 403);
        }
        $device = AttendanceDevice::query()->where('serial_number', $serial)
            ->where('is_active', true)->where('connection_mode', 'adms')->first();
        $request->attributes->set('attendance_device', $device);
        $reason = null;
        if (! $device) {
            $reason = 'unknown_inactive_or_wrong_mode';
        } elseif (! $device->adms_source_ip || @inet_pton($peer) === false
            || @inet_pton($device->adms_source_ip) === false
            || @inet_pton($peer) !== @inet_pton($device->adms_source_ip)) {
            $reason = 'source_ip_mismatch';
        } elseif (! $request->isSecure() && ! config('attendance.adms.allow_http')) {
            $reason = 'https_required';
        }
        if ($reason !== null) {
            AdmsDiagnostics::record($request, 403, $reason);

            return response('FORBIDDEN', 403);
        }
        if (strlen($request->getContent()) > config('attendance.adms.max_body_bytes')) {
            AdmsDiagnostics::record($request, 413, 'payload_too_large');

            return response('PAYLOAD TOO LARGE', 413);
        }
        $response = $next($request);
        AdmsDiagnostics::record($request, $response->getStatusCode(),
            $request->attributes->get('adms_diagnostic_reason', $response->getStatusCode() >= 400 ? 'request_rejected' : 'ok'));

        return $response;
    }
}
