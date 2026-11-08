<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class ApprovalLimit extends Model
{
    protected $table = 'fin_approval_limits';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }
}
