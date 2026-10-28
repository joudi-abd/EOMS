<?php

namespace App\Modules\HR\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    protected $table = 'hr_employees';

    protected $fillable = [
        'employee_no', 'user_id', 'first_name', 'father_name', 'last_name',
        'national_id', 'national_id_hash', 'birth_date', 'email', 'phone', 'address',
        'department_id', 'job_title_id', 'manager_id', 'biometric_code',
        'sham_cash_wallet', 'status', 'hire_date', 'termination_date', 'termination_reason',
    ];

    // الرقم الوطني لا يظهر أبداً في أي استجابة JSON
    protected $hidden = ['national_id', 'national_id_hash'];

    protected function casts(): array
    {
        return [
            'national_id' => 'encrypted',
            'birth_date' => 'date:Y-m-d',
            'hire_date' => 'date:Y-m-d',
            'termination_date' => 'date:Y-m-d',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function jobTitle(): BelongsTo
    {
        return $this->belongsTo(JobTitle::class, 'job_title_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    public function subordinates(): HasMany
    {
        return $this->hasMany(self::class, 'manager_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class, 'employee_id');
    }

    public function getFullNameAttribute(): string
    {
        return trim(implode(' ', array_filter([$this->first_name, $this->father_name, $this->last_name], fn ($part) => filled($part))));
    }

    public function jobHistories(): HasMany
    {
        return $this->hasMany(EmployeeJobHistory::class, 'employee_id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(EmploymentContract::class, 'employee_id');
    }
}
