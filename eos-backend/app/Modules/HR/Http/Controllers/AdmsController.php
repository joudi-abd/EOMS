<?php

namespace App\Modules\HR\Http\Controllers;

use App\Modules\HR\Models\AttendanceBinding;
use App\Modules\HR\Models\AttendanceDevice;
use App\Modules\HR\Services\Attendance\AdmsDiagnostics;
use App\Modules\HR\Services\Attendance\AdmsGateway;
use App\Modules\HR\Services\Attendance\AdmsQuarantineService;
use App\Support\ApiResponse;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class AdmsController extends Controller
{
    public function device(Request $request, AdmsGateway $gateway): Response
    {
        $device = $request->attributes->get('attendance_device');
        $action = basename(preg_replace('/\.aspx$/', '', $request->path()));
        try {
            $body = match ($action) {
                'cdata' => $request->isMethod('GET') ? $gateway->options($device) : $this->upload($request, $gateway, $device),
                'getrequest' => $gateway->poll($device),
                'devicecmd' => $this->ack($request, $gateway, $device),
                'ping', 'registry' => $this->heartbeat($gateway, $device),
                default => abort(404),
            };
        } catch (HttpExceptionInterface $e) {
            if (! $request->attributes->has('adms_diagnostic_reason')) {
                $request->attributes->set('adms_diagnostic_reason', 'request_rejected');
            }

            return response('REQUEST REJECTED', $e->getStatusCode(), ['Content-Type' => 'text/plain', 'Cache-Control' => 'no-store']);
        } catch (ValidationException $e) {
            $request->attributes->set('adms_diagnostic_reason', AdmsDiagnostics::validationReason($e));

            return response('INVALID DATA', 422, ['Content-Type' => 'text/plain', 'Cache-Control' => 'no-store']);
        } catch (QueryException) {
            $request->attributes->set('adms_diagnostic_reason', 'database_error');

            return response('TEMPORARY ERROR', 503, ['Content-Type' => 'text/plain', 'Cache-Control' => 'no-store']);
        } catch (\Throwable) {
            $request->attributes->set('adms_diagnostic_reason', 'internal_error');

            return response('TEMPORARY ERROR', 503, ['Content-Type' => 'text/plain', 'Cache-Control' => 'no-store']);
        }

        return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    private function upload(Request $request, AdmsGateway $gateway, AttendanceDevice $device): string
    {
        $table = $request->query('table');
        $request->attributes->set('adms_diagnostic_reason', 'missing_table');
        abort_unless(is_string($table), 422);
        if (strcasecmp($table, 'ATTLOG') === 0) {
            $result = $gateway->ingest($device, $request->getContent());
            $request->attributes->set('adms_diagnostic_reason', $result['quarantined'] > 0 ? 'attlog_saved_with_quarantine' : 'attlog_saved');
            $request->attributes->set('adms_accepted_records', $result['received']);
            $request->attributes->set('adms_imported_records', $result['imported']);
            $request->attributes->set('adms_quarantined_records', $result['quarantined']);
            $request->attributes->set('adms_duplicate_records', $result['duplicates']);

            return 'OK: '.$result['received']."\n";
        }
        if (strcasecmp($table, 'options') === 0) {
            $gateway->touch($device);
            $request->attributes->set('adms_diagnostic_reason', 'options_received');

            return 'OK';
        }
        // Do not silently discard biometric templates/unknown upload formats.
        $request->attributes->set('adms_diagnostic_reason', 'unsupported_table');
        abort(503, 'UNSUPPORTED UPLOAD TABLE');
    }

    private function ack(Request $request, AdmsGateway $gateway, AttendanceDevice $device): string
    {
        $raw = $request->getContent();
        $lines = $raw === '' ? [http_build_query($request->query())] : preg_split('/\r\n|\n|\r/', trim($raw));
        $records = [];
        foreach ($lines as $line) {
            parse_str($line, $fields);
            $id = $fields['ID'] ?? $fields['CmdID'] ?? '';
            $code = $fields['Return'] ?? null;
            $cmd = $fields['CMD'] ?? '';
            abort_unless(is_string($id) && preg_match('/^[0-9]{1,18}$/D', $id)
                && is_string($code) && preg_match('/^-?[0-9]{1,10}$/D', $code)
                && is_string($cmd) && in_array(strtoupper($cmd), ['DATA', 'ENROLL_FP'], true), 422);
            $records[] = ['ID' => $id, 'Return' => $code, 'CMD' => $cmd];
        }
        $gateway->acknowledge($device, $records);

        return 'OK';
    }

    private function heartbeat(AdmsGateway $gateway, AttendanceDevice $device): string
    {
        $gateway->touch($device);

        return 'OK';
    }

    public function quarantined(Request $request, AdmsQuarantineService $quarantine): JsonResponse
    {
        $data = $request->validate([
            'device_id' => ['sometimes', 'integer', 'exists:hr_attendance_devices,id'],
            'status' => ['sometimes', Rule::in(['pending', 'released', 'dismissed'])],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        return ApiResponse::paginated($quarantine->list($data));
    }

    public function reviewQuarantined(Request $request, int $quarantine, AdmsQuarantineService $service): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['release', 'dismiss'])],
            'corrected_occurred_at' => ['required_if:action,release', 'nullable', 'string', 'max:40'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        return ApiResponse::ok($service->review($quarantine, $data, $request->user()->id));
    }

    public function configure(Request $request, AttendanceDevice $device, AdmsGateway $gateway): JsonResponse
    {
        $data = $request->validate([
            'connection_mode' => ['required', Rule::in(['bridge', 'adms'])],
            'adms_source_ip' => ['required_if:connection_mode,adms', 'nullable', 'ip'],
        ]);
        if ($data['connection_mode'] === 'adms') {
            abort_unless(preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $device->serial_number), 422, 'Invalid device serial format.');
        }

        return ApiResponse::ok($gateway->configure($device, $data));
    }

    public function enroll(Request $request, AttendanceBinding $binding, AdmsGateway $gateway): JsonResponse
    {
        $data = $request->validate([
            'finger_id' => ['required', 'integer', 'between:0,9'],
            // USERINFO updates a device user; require explicit acknowledgement of that effect.
            'confirm_user_update' => ['required', 'accepted'],
        ]);

        return ApiResponse::created($gateway->startEnrollment($binding, $data['finger_id'], $request->user()->id));
    }

    public function confirm(Request $request, int $enrollment, AdmsGateway $gateway): JsonResponse
    {
        $data = $request->validate(['physical_test_passed' => ['required', 'accepted'],
            'note' => ['required', 'string', 'min:5', 'max:500']]);

        return ApiResponse::ok($gateway->confirm($enrollment, $request->user()->id, $data['note']));
    }

    public function latest(AttendanceBinding $binding, AdmsGateway $gateway): JsonResponse
    {
        return ApiResponse::ok($gateway->latestForBinding($binding));
    }

    public function cancel(Request $request, int $enrollment, AdmsGateway $gateway): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:5', 'max:500']]);

        return ApiResponse::ok($gateway->cancel($enrollment, $request->user()->id, $data['note']));
    }

    public function status(int $enrollment, AdmsGateway $gateway): JsonResponse
    {
        return ApiResponse::ok($gateway->enrollment($enrollment));
    }
}
