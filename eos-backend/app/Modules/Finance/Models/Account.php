<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    protected $table = 'fin_accounts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['parent_id' => 'integer', 'active' => 'boolean'];
    }
}
