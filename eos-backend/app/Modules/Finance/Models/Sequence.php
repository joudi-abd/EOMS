<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

/** Gapless document numbers per type, year and branch. */
class Sequence extends Model
{
    protected $table = 'fin_sequences';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['year' => 'integer', 'branch_id' => 'integer', 'next_number' => 'integer'];
    }
}
