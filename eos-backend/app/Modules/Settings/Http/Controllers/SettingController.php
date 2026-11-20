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
            'group' => ['sometimes', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/'],
        ]);
        abort_unless(preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/', $key) === 1 && strlen($key) <= 120, 422,
            'مفتاح الإعداد يجب أن يكون بصيغة group.name بأحرف إنجليزية صغيرة.');

        $setting = $this->settings->set($key, $data['value'], $data['group'] ?? null);

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
