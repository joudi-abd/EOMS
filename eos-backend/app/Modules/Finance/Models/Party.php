<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Anyone the company deals with (D18): client, supplier, contractor, employee, partner, other. */
class Party extends Model
{
    public const ROLES = ['client', 'supplier', 'contractor', 'employee', 'partner', 'other'];

    protected $table = 'fin_parties';

    protected $guarded = ['id'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['employee_id' => 'integer', 'is_active' => 'boolean', 'created_by' => 'integer', 'updated_by' => 'integer'];
    }

    public function roles(): HasMany
    {
        return $this->hasMany(PartyRole::class, 'party_id');
    }

    public function partner(): HasOne
    {
        return $this->hasOne(Partner::class, 'party_id');
    }
}
