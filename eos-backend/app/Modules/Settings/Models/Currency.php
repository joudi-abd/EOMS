<?php

namespace App\Modules\Settings\Models;

use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    protected $table = 'sys_currencies';

    protected $fillable = ['code', 'name', 'symbol', 'decimals', 'rounding_step', 'is_base', 'is_payroll_currency', 'is_active'];

    protected $attributes = ['decimals' => 2, 'rounding_step' => 1, 'is_base' => false, 'is_payroll_currency' => false, 'is_active' => true];

    protected function casts(): array
    {
        return ['decimals' => 'integer', 'rounding_step' => 'decimal:4', 'is_base' => 'boolean',
            'is_payroll_currency' => 'boolean', 'is_active' => 'boolean'];
    }
}
