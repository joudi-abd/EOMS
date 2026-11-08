<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class Party extends Model
{
    protected $table = 'fin_parties';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
