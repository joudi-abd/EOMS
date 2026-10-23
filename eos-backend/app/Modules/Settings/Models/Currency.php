<?php

namespace App\Modules\Settings\Models;

use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    protected $table = 'sys_currencies';

    protected $fillable = ['code', 'name', 'symbol'];
}