<?php

namespace App\Modules\Settings\Models;

use Illuminate\Database\Eloquent\Model;

class ApiClient extends Model
{
    protected $table = 'sys_api_clients';

    protected $fillable = ['name', 'token_hash', 'scopes', 'is_active'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }
}