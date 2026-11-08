<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveRequest extends Model
{
    protected $table = 'hr_leave_requests';
    protected $guarded = ['id'];

    // Keep a newly created model consistent with the database defaults.
    protected $attributes = ['status' => 'pending', 'version' => 1];
    protected function casts(): array
    {
        return ['employee_id' => 'integer', 'service_period_id' => 'integer', 'leave_type_id' => 'integer', 'submitted_by' => 'integer', 'entered_by' => 'integer', 'reviewed_by' => 'integer', 'policy' => 'array', 'input' => 'array', 'change_request' => 'array', 'unpaid_consent' => 'boolean', 'version' => 'integer'];
    }

    public function days(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(LeaveRequestDay::class)->orderBy('date')->orderBy('id');
    }
}
