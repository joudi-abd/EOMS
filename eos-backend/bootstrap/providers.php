<?php

use App\Modules\HR\Providers\HrServiceProvider;
use App\Modules\Identity\Providers\IdentityServiceProvider;
use App\Modules\Payroll\Providers\PayrollServiceProvider;
use App\Modules\Settings\Providers\SettingsServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    SettingsServiceProvider::class,
    IdentityServiceProvider::class,
    HrServiceProvider::class,
    PayrollServiceProvider::class,
];
