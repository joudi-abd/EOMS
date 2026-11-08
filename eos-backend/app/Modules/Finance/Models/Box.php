<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class Box extends Model
{
    protected $table = 'fin_boxes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['account_id' => 'integer', 'active' => 'boolean'];
    }
}
