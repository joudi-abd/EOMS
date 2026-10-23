<?php

namespace App\Modules\Settings\Services;

use App\Modules\Settings\Models\Setting;

class SettingService
{
    public function get(string $key, mixed $default = null): mixed
    {
        return Setting::find($key)?->setting_value ?? $default;
    }

    public function set(string $key, string $value, string $group = 'general'): Setting
    {
        return Setting::updateOrCreate(
            ['setting_key' => $key],
            ['setting_value' => $value, 'setting_group' => $group]
        );
    }

    public function group(string $group): array
    {
        return Setting::where('setting_group', $group)
            ->pluck('setting_value', 'setting_key')
            ->all();
    }
}