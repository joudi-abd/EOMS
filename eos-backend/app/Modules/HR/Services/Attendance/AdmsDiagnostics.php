<?php

namespace App\Modules\HR\Services\Attendance;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AdmsDiagnostics
{
    public static function record(Request $request, int $status, string $reason): void
    {
        if ($status < 400 && ! config('attendance.adms.diagnostics')) {
            return;
        }
        $table = $request->query('table');
        $table = is_string($table) ? strtoupper($table) : 'NONE';
        if (! in_array($table, ['ATTLOG', 'OPERLOG', 'OPTIONS', 'FINGERTMP', 'BIODATA', 'ATTPHOTO', 'NONE'], true)) {
            $table = 'OTHER';
        }
        $device = $request->attributes->get('attendance_device');
        $context = [
            'device_id' => $device?->id,
            'method' => $request->method(),
            'endpoint' => basename($request->path()),
            'table' => $table,
            'http_status' => $status,
            'reason' => $reason,
            'body_bytes' => strlen($request->getContent()),
            'server_utc' => CarbonImmutable::now('UTC')->toIso8601String(),
            'device_timezone' => $device?->timezone,
        ];
        if ($table === 'ATTLOG' && $request->isMethod('POST') && $device) {
            $line = preg_split('/\r\n|\n|\r/', $request->getContent(), 2)[0];
            $context['first_line_fields'] = count(explode("\t", $line));
            $context['utf8_bom'] = str_starts_with($line, "\xEF\xBB\xBF");
        }
        if ($request->attributes->has('adms_accepted_records')) {
            $context['accepted_records'] = $request->attributes->get('adms_accepted_records');
        }
        try {
            // Never log SN, PIN, query strings, payloads, or exception messages with SQL bindings.
            Log::channel('adms')->log($status >= 400 ? 'warning' : 'info', 'adms.request', $context);
        } catch (\Throwable) {
            // Diagnostics must not turn a committed upload's ACK into a failure/retry.
        }
    }

    public static function validationReason(ValidationException $exception): string
    {
        $errors = $exception->errors();
        $message = $errors['body'][0] ?? $errors['occurred_at'][0] ?? '';

        return match ($message) {
            'Invalid ATTLOG record.' => 'invalid_attlog_record',
            'Invalid ATTLOG timestamp.' => 'invalid_attlog_timestamp',
            'Too many attendance records.' => 'too_many_records',
            'وقت الحركة في المستقبل؛ تحقق من ساعة الجهاز.' => 'future_event_time',
            default => array_key_exists('occurred_at', $errors) ? 'invalid_event_time' : 'validation_failed',
        };
    }
}
