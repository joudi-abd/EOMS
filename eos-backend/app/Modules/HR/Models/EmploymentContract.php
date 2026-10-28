<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmploymentContract extends Model
{
    protected $table = 'hr_employment_contracts';

    protected $fillable = [
        'employee_id', 'contract_number', 'type', 'status',
        'start_date', 'end_date', 'terminated_at', 'termination_reason',
        'renewed_from_id', 'base_salary', 'currency_code',
        'scheduled_termination_at', 'scheduled_termination_reason',
    ];

    protected $hidden = ['base_salary', 'currency_code'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'terminated_at' => 'date:Y-m-d',
            'scheduled_termination_at' => 'date:Y-m-d',
            'base_salary' => 'decimal:2',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function previousContract(): BelongsTo
    {
        return $this->belongsTo(self::class, 'renewed_from_id');
    }

    public function renewals(): HasMany
    {
        return $this->hasMany(self::class, 'renewed_from_id');
    }
}
