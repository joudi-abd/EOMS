<?php

use App\Modules\HR\Providers\HrServiceProvider;
use App\Modules\Identity\Providers\IdentityServiceProvider;
use App\Providers\AppServiceProvider;
use App\Modules\Settings\Providers\SettingsServiceProvider;

return [
    AppServiceProvider::class,
    SettingsServiceProvider::class,
    IdentityServiceProvider::class,
    HrServiceProvider::class,
];
