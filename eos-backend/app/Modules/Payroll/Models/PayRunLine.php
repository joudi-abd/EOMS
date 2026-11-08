<?php

namespace App\Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PayRunLine extends Model
{
    protected $table = 'pay_run_lines';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['pay_run_id' => 'integer', 'employee_id' => 'integer', 'contract_id' => 'integer',
            'rate' => 'decimal:2', 'working_days' => 'integer', 'attendance_ready' => 'boolean',
            'blocked_days' => 'integer', 'issues' => 'array', 'earnings' => 'decimal:2',
            'deductions' => 'decimal:2', 'net' => 'decimal:2'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayRun::class, 'pay_run_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayRunItem::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(PayPayment::class);
    }
}
