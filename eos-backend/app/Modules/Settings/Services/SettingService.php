<?php

namespace App\Modules\Settings\Services;

use App\Modules\Settings\Models\Setting;
use App\Support\AuditableAction;
use Illuminate\Support\Facades\DB;

class SettingService
{
    public function get(string $key, mixed $default = null): mixed
    {
        return Setting::find($key)?->setting_value ?? $default;
    }

    /** Saves a value. An existing setting keeps its group unless one is given; a new one defaults to `general`. */
    public function set(string $key, string $value, ?string $group = null): Setting
    {
        return DB::transaction(function () use ($key, $value, $group) {
            $setting = Setting::query()->lockForUpdate()->find($key);
            $old = $setting === null ? null : ['value' => $setting->setting_value, 'group' => $setting->setting_group];
            $setting ??= new Setting(['setting_key' => $key]);
            $setting->setting_value = $value;
            $setting->setting_group = $group ?? $setting->setting_group ?? 'general';
            $setting->save();
            event(new AuditableAction('settings.updated', null, $old === null ? null : ['key' => $key] + $old,
                ['key' => $key, 'value' => $value, 'group' => $setting->setting_group]));

            return $setting;
        }, 3);
    }

    /** @return object key => value (an object even when the group is empty, so clients always get `{}`) */
    public function group(string $group): object
    {
        return (object) Setting::where('setting_group', $group)
            ->pluck('setting_value', 'setting_key')
            ->all();
    }

    public function publicSettings(): object
    {
        $allowedKeys = [
            'company.name',
        ];

        return (object) Setting::query()
            ->whereIn('setting_key', $allowedKeys)
            ->pluck('setting_value', 'setting_key')
            ->all();
    }
}
