<?php

use App\Modules\Identity\Providers\IdentityServiceProvider;
use App\Providers\AppServiceProvider;
use App\Modules\Settings\Providers\SettingsServiceProvider;

return [
    AppServiceProvider::class,
    SettingsServiceProvider::class,
    IdentityServiceProvider::class,
];
