<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeJobHistory extends Model
{
    protected $table = 'hr_employee_job_histories';

    protected $fillable = [
        'employee_id', 'event_type', 'reason',
        'old_department_id', 'new_department_id',
        'old_job_title_id', 'new_job_title_id',
        'old_manager_id', 'new_manager_id',
        'effective_date', 'recorded_by_user_id',
    ];

    protected function casts(): array
    {
        return ['effective_date' => 'date:Y-m-d'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
