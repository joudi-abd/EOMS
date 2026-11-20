<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

/** A branch assigned to an account: finance permissions held at "own" scope reach it. */
class UserBranch extends Model
{
    protected $table = 'fin_user_branches';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'branch_id' => 'integer'];
    }
}
