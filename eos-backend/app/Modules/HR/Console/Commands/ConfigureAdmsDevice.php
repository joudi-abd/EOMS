<?php

namespace App\Modules\HR\Console\Commands;

use App\Modules\HR\Models\AttendanceDevice;
use App\Modules\HR\Models\AttendancePolicy;
use App\Modules\HR\Services\Attendance\AdmsGateway;
use App\Modules\HR\Services\Attendance\AttendanceDeviceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ConfigureAdmsDevice extends Command
{
    protected $signature = 'attendance:configure-adms {serial : Serial from the device information screen} {ip : Device source IP visible to Laravel} {--name=Office iFace880 Plus}';

    protected $description = 'Create or configure an attendance device for native ADMS/PUSH; does not contact the device';

    public function handle(AdmsGateway $gateway, AttendanceDeviceService $devices): int
    {
        $validator = Validator::make(['serial' => $this->argument('serial'), 'ip' => $this->argument('ip'), 'name' => $this->option('name')], [
            'serial' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,100}$/D'],
            'ip' => ['required', 'ip'], 'name' => ['required', 'string', 'max:120'],
        ]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }
        $data = $validator->validated();
        $device = DB::transaction(function () use ($data, $gateway, $devices) {
            $device = AttendanceDevice::query()->where('serial_number', $data['serial'])->first();
            if (! $device) {
                $device = $devices->create(['name' => $data['name'], 'serial_number' => $data['serial'],
                    'timezone' => AttendancePolicy::query()->orderBy('effective_from')->value('timezone'),
                    'model' => 'iFace 880 Plus'])['device'];
            }

            return $gateway->configure($device, ['connection_mode' => 'adms', 'adms_source_ip' => $data['ip']]);
        }, 3);
        $this->info('Configured device ID '.$device->id.'; mode=adms; source IP='.$device->adms_source_ip);
        $this->line('No network connection has been tested. Enable ATTENDANCE_ADMS_ENABLED and point the device Cloud Server to your Laravel host.');

        return self::SUCCESS;
    }
}
