<?php

namespace App\Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayRun extends Model
{
    protected $table = 'pay_runs';

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'draft', 'version' => 1];

    protected function casts(): array
    {
        return ['prepared_by' => 'integer', 'approved_by' => 'integer', 'version' => 'integer',
            'calculated_at' => 'datetime', 'submitted_at' => 'datetime', 'approved_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayRunLine::class);
    }
}
