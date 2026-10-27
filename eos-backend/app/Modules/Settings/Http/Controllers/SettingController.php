<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Settings\Services\SettingService;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

#[Group('07 - Settings / Configuration', weight: 7)]
class SettingController extends Controller
{
    public function __construct(private SettingService $settings) {}

    /**
     * List settings by group.
     *
     * Returns a list of settings for a specific group.
     */
    public function group(string $group)
    {
        return ApiResponse::ok($this->settings->group($group));
    }

    /**
     * Update a setting.
     *
     * Updates the value of a specific setting.
     */
    public function update(Request $request, string $key)
    {
        $data = $request->validate([
            'value' => ['required', 'string', 'max:1000'],
            'group' => ['sometimes', 'string', 'max:60'],
        ]);

        $setting = $this->settings->set($key, $data['value'], $data['group'] ?? 'general');

        return ApiResponse::ok([
            'key' => $setting->setting_key,
            'value' => $setting->setting_value,
            'group' => $setting->setting_group,
        ]);
    }

    /**
     * Get public application settings.
     *
     * Returns a safe subset of application settings.
     */
    public function publicSettings()
    {
        return ApiResponse::ok($this->settings->publicSettings());
    }
}
