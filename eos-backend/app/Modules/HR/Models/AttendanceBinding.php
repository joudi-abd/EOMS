<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AttendanceBinding extends Model
{
    public function device(): BelongsTo
    {
        return $this->belongsTo(AttendanceDevice::class, 'device_id');
    }

    public function latestEnrollment(): HasOne
    {
        return $this->hasOne(AttendanceEnrollment::class, 'binding_id')->latestOfMany();
    }

    public function scopeValidOn(Builder $query, string $date): void
    {
        $query->whereDate('valid_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date))
            ->where(fn ($q) => $q->whereNull('revoked_from')->orWhereDate('revoked_from', '>', $date));
    }

    public function isValidOn(string $date): bool
    {
        return $this->valid_from->format('Y-m-d') <= $date
            && (! $this->valid_to || $this->valid_to->format('Y-m-d') >= $date)
            && (! $this->revoked_from || $this->revoked_from->format('Y-m-d') > $date);
    }

    protected $table = 'hr_attendance_bindings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['valid_from' => 'date:Y-m-d', 'valid_to' => 'date:Y-m-d', 'revoked_from' => 'date:Y-m-d'];
    }
}
