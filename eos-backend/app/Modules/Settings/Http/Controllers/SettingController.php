<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Settings\Services\SettingService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class SettingController extends Controller
{
    public function __construct(private SettingService $settings) {}

    public function group(string $group)
    {
        return ApiResponse::ok($this->settings->group($group));
    }

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
}