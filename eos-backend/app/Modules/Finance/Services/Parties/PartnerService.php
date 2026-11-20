<?php

namespace App\Modules\Finance\Services\Parties;

use App\Modules\Finance\Models\Partner;
use App\Modules\Finance\Models\Party;
use App\Modules\Finance\Models\PartyRole;
use App\Modules\Finance\Support\Money;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Partners (D16, spec §5.4): the general manager today, several partners with shares later. A partner is a party that
 * holds the `partner` role. Rule: the shares of the active partners add up to exactly 100, or are all empty (a single
 * owner). Several shares change together through `other_shares` (partner id => share) in the same call.
 */
class PartnerService
{
    private const TX_ATTEMPTS = 3;

    public function __construct(private AuditLogger $audit) {}

    /** @return Collection<int, Partner> */
    public function list(?bool $active = null): Collection
    {
        return Partner::query()->with('party:id,name,phone,is_active')
            ->when($active !== null, fn ($q) => $q->where('is_active', $active))->orderByDesc('is_active')->orderBy('id')->get();
    }

    /** @param  array{party_id: int, share_pct?: string|null, joined_at?: string|null, is_active?: bool, other_shares?: array<int, string|null>}  $data */
    public function create(array $data, User $actor): Partner
    {
        return DB::transaction(function () use ($data, $actor) {
            $party = Party::query()->lockForUpdate()->findOrFail($data['party_id']);
            if (! $party->is_active) {
                throw ValidationException::withMessages(['party_id' => ['جهة التعامل غير فعّالة.']]);
            }
            if (Partner::query()->where('party_id', $party->id)->exists()) {
                throw ValidationException::withMessages(['party_id' => ['هذه الجهة مسجّلة شريكاً مسبقاً.']]);
            }
            $this->lockPartners();
            $partner = Partner::create(['party_id' => $party->id, 'share_pct' => $this->share($data['share_pct'] ?? null),
                'joined_at' => $data['joined_at'] ?? null, 'is_active' => $data['is_active'] ?? true, 'created_by' => $actor->id, 'updated_by' => $actor->id]);
            PartyRole::query()->firstOrCreate(['party_id' => $party->id, 'role' => 'partner']);
            $this->applyOtherShares($data['other_shares'] ?? [], $partner->id, $actor);
            $this->assertShares();
            $this->audit->log('finance.partner_created', $partner, null, $partner->getAttributes(), $actor->id);

            return $partner->refresh()->load('party:id,name,phone,is_active');
        }, self::TX_ATTEMPTS);
    }

    /** @param  array{share_pct?: string|null, joined_at?: string|null, is_active?: bool, other_shares?: array<int, string|null>}  $data */
    public function update(Partner $partner, array $data, User $actor): Partner
    {
        return DB::transaction(function () use ($partner, $data, $actor) {
            $this->lockPartners();
            $partner = Partner::query()->findOrFail($partner->id);
            $old = $partner->getAttributes();
            $changes = array_intersect_key($data, array_flip(['joined_at', 'is_active']));
            if (array_key_exists('share_pct', $data)) {
                $changes['share_pct'] = $this->share($data['share_pct']);
            }
            $partner->update([...$changes, 'updated_by' => $actor->id]);
            $this->applyOtherShares($data['other_shares'] ?? [], $partner->id, $actor);
            $this->assertShares();
            $this->audit->log('finance.partner_updated', $partner, $old, $partner->getAttributes(), $actor->id);

            return $partner->refresh()->load('party:id,name,phone,is_active');
        }, self::TX_ATTEMPTS);
    }

    /** Lock every partner row (ascending id) so two share changes cannot both pass the 100 % check. */
    private function lockPartners(): void
    {
        Partner::query()->orderBy('id')->lockForUpdate()->get(['id']);
    }

    /** @param  array<int|string, string|int|float|null>  $shares */
    private function applyOtherShares(array $shares, int $selfId, User $actor): void
    {
        foreach ($shares as $partnerId => $share) {
            if ((int) $partnerId === $selfId) {
                continue;
            }
            $other = Partner::query()->find((int) $partnerId);
            if ($other === null) {
                throw ValidationException::withMessages(['other_shares' => ['أحد الشركاء غير موجود.']]);
            }
            $old = $other->getAttributes();
            $other->update(['share_pct' => $this->share($share), 'updated_by' => $actor->id]);
            $this->audit->log('finance.partner_updated', $other, $old, $other->getAttributes(), $actor->id);
        }
    }

    /** D16: the active partners' shares are all empty or add up to exactly 100. */
    private function assertShares(): void
    {
        $shares = Partner::query()->where('is_active', true)->pluck('share_pct');
        $filled = $shares->filter(fn ($s) => $s !== null);
        if ($filled->isEmpty()) {
            return;
        }
        if ($filled->count() !== $shares->count() || Money::cmp(Money::sum($filled->map(fn ($s) => (string) $s)), '100') !== 0) {
            throw ValidationException::withMessages(['share_pct' => ['مجموع حصص الشركاء النشطين يجب أن يساوي 100%، أو تُترك الحصص كلها فارغة.']]);
        }
    }

    private function share(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $share = Money::of((string) $value);
        if (! Money::isPositive($share) || Money::cmp($share, '100') > 0) {
            throw ValidationException::withMessages(['share_pct' => ['الحصة نسبة بين 0 و100.']]);
        }

        return $share;
    }
}
