<?php

namespace App\Modules\Finance\Services\Parties;

use App\Modules\Finance\Models\Partner;
use App\Modules\Finance\Models\Party;
use App\Modules\Finance\Models\PartyRole;
use App\Modules\HR\Models\Employee;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use App\Modules\Settings\Services\SettingService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Parties (D18, spec §5.4): one record for any counterparty, with several roles (client, supplier, contractor,
 * employee, partner, other), an optional link to an HR employee, the contractor's tracking mode (D11) and trade, and a
 * Sham Cash wallet. Parties are deactivated, never deleted (R4: an inactive party takes no new postings).
 */
class PartyService
{
    private const TX_ATTEMPTS = 3;

    public const TRACKING_MODES = ['agreement', 'payments_only'];

    /** Columns copied from the payload as they are. */
    private const FIELDS = ['name', 'phone', 'address', 'notes', 'employee_id', 'contractor_tracking_mode', 'trade', 'sham_cash_wallet', 'sham_cash_name', 'is_active'];

    /** CSV import: at most this many data rows per file. */
    public const IMPORT_MAX_ROWS = 2000;

    public function __construct(private PartyStatementService $statements, private SettingService $settings, private AuditLogger $audit) {}

    /** @param  array{role?: string, search?: string, active?: bool, employee_id?: int, per_page?: int}  $filters */
    public function list(array $filters): LengthAwarePaginator
    {
        return Party::query()->with('roles:id,party_id,role')
            ->when($filters['role'] ?? null, fn (Builder $q, $role) => $q->whereHas('roles', fn (Builder $r) => $r->where('role', $role)))
            ->when(array_key_exists('active', $filters), fn (Builder $q) => $q->where('is_active', (bool) $filters['active']))
            ->when($filters['employee_id'] ?? null, fn (Builder $q, $id) => $q->where('employee_id', $id))
            ->when($filters['search'] ?? null, fn (Builder $q, $term) => $q->where(fn (Builder $q) => $q->where('name', 'like', "%$term%")
                ->orWhere('phone', 'like', "%$term%")->orWhere('trade', 'like', "%$term%")))
            ->orderBy('name')->orderBy('id')
            ->paginate(min(100, (int) ($filters['per_page'] ?? 25)))
            ->through(fn (Party $party) => $this->summary($party));
    }

    /** Party card: the record, its roles, partner row, effective contractor mode and balances per currency (spec §9.3). */
    public function present(Party $party, ?array $branchIds = null): array
    {
        return [...$this->summary($party->load('roles')), 'partner' => Partner::query()->where('party_id', $party->id)->first(),
            'balances' => $this->statements->balances($party, $branchIds)];
    }

    public function create(array $data, User $actor): Party
    {
        return DB::transaction(function () use ($data, $actor) {
            $attributes = $this->attributes($data, null);
            $party = Party::create([...$attributes, 'created_by' => $actor->id, 'updated_by' => $actor->id]);
            $this->syncRoles($party, $data['roles'] ?? []);
            $this->audit->log('finance.party_created', $party, null, [...$party->getAttributes(), 'roles' => $this->roleNames($party)], $actor->id);

            return $party->refresh();
        }, self::TX_ATTEMPTS);
    }

    public function update(Party $party, array $data, User $actor): Party
    {
        return DB::transaction(function () use ($party, $data, $actor) {
            $party = Party::query()->lockForUpdate()->findOrFail($party->id);
            $old = [...$party->getAttributes(), 'roles' => $this->roleNames($party)];
            $party->update([...$this->attributes($data, $party), 'updated_by' => $actor->id]);
            if (array_key_exists('roles', $data)) {
                $this->syncRoles($party, $data['roles']);
            }
            $this->audit->log('finance.party_updated', $party, $old, [...$party->getAttributes(), 'roles' => $this->roleNames($party)], $actor->id);

            return $party->refresh();
        }, self::TX_ATTEMPTS);
    }

    /** R4: an inactive party keeps its history but takes no new postings. */
    public function deactivate(Party $party, User $actor): Party
    {
        return DB::transaction(function () use ($party, $actor) {
            $party = Party::query()->lockForUpdate()->findOrFail($party->id);
            if (! $party->is_active) {
                return $party;
            }
            $party->update(['is_active' => false, 'updated_by' => $actor->id]);
            $this->audit->log('finance.party_deactivated', $party, ['is_active' => true], ['is_active' => false], $actor->id);

            return $party->refresh();
        }, self::TX_ATTEMPTS);
    }

    /**
     * Setup wizard step 5 (OP-15): CSV with a header row `name,phone,role,trade` (role: one of the party roles, or
     * several separated by "|"). Rows whose name and phone match an existing party only add the missing roles. Every
     * row is checked first; any bad row rejects the whole file (422 listing the rows).
     *
     * @return array{created: int, updated: int, skipped: int}
     */
    public function importCsv(UploadedFile $file, User $actor): array
    {
        $rows = $this->readCsv($file);

        return DB::transaction(function () use ($rows, $actor) {
            $created = $updated = $skipped = 0;
            foreach ($rows as $row) {
                $existing = Party::query()->where('name', $row['name'])
                    ->where(fn (Builder $q) => $row['phone'] === null ? $q->whereNull('phone') : $q->where('phone', $row['phone']))->first();
                if ($existing === null) {
                    $this->create(['name' => $row['name'], 'phone' => $row['phone'], 'trade' => $row['trade'], 'roles' => $row['roles']], $actor);
                    $created++;

                    continue;
                }
                $missing = array_values(array_diff($row['roles'], $this->roleNames($existing)));
                if ($missing === []) {
                    $skipped++;

                    continue;
                }
                $this->update($existing, ['roles' => [...$this->roleNames($existing), ...$missing]], $actor);
                $updated++;
            }
            $this->audit->log('finance.parties_imported', null, null, ['created' => $created, 'updated' => $updated, 'skipped' => $skipped], $actor->id);

            return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
        }, self::TX_ATTEMPTS);
    }

    /** The contractor tracking mode that applies to the party: its own, else the general setting (D11). */
    public function trackingMode(Party $party): string
    {
        return $party->contractor_tracking_mode ?? (string) $this->settings->get('finance.contractor_tracking_mode_default', 'agreement');
    }

    /** Roles held by a party. @return list<string> */
    public function roleNames(Party $party): array
    {
        return PartyRole::query()->where('party_id', $party->id)->orderBy('role')->pluck('role')->all();
    }

    private function summary(Party $party): array
    {
        return [...$party->toArray(), 'roles' => $party->relationLoaded('roles') ? $party->roles->pluck('role')->sort()->values()->all() : $this->roleNames($party),
            'effective_tracking_mode' => $this->trackingMode($party)];
    }

    private function attributes(array $data, ?Party $party): array
    {
        $attributes = array_intersect_key($data, array_flip(self::FIELDS));
        if (array_key_exists('employee_id', $attributes) && $attributes['employee_id'] !== null) {
            $employeeId = (int) $attributes['employee_id'];
            if (! Employee::query()->whereKey($employeeId)->exists()) {
                throw ValidationException::withMessages(['employee_id' => ['الموظف غير موجود.']]);
            }
            $taken = Party::query()->where('employee_id', $employeeId)->when($party, fn (Builder $q) => $q->whereKeyNot($party->id))->value('name');
            if ($taken !== null) {
                throw ValidationException::withMessages(['employee_id' => ["هذا الموظف مرتبط بجهة التعامل {$taken}."]]);
            }
        }

        return $attributes;
    }

    /** @param  list<string>  $roles */
    private function syncRoles(Party $party, array $roles): void
    {
        $roles = array_values(array_unique($roles));
        if ($roles === []) {
            throw ValidationException::withMessages(['roles' => ['اختر دوراً واحداً على الأقل.']]);
        }
        if (! in_array('partner', $roles, true) && Partner::query()->where('party_id', $party->id)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['roles' => ['الجهة شريك نشط؛ أوقف الشراكة قبل إزالة دور الشريك.']]);
        }
        PartyRole::query()->where('party_id', $party->id)->whereNotIn('role', $roles)->delete();
        $existing = $this->roleNames($party);
        foreach (array_diff($roles, $existing) as $role) {
            PartyRole::create(['party_id' => $party->id, 'role' => $role]);
        }
    }

    /** @return list<array{name: string, phone: ?string, trade: ?string, roles: list<string>}> */
    private function readCsv(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            throw ValidationException::withMessages(['file' => ['تعذّرت قراءة الملف.']]);
        }
        $header = fgetcsv($handle, null, ',', '"', '');
        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $header ?: []);
        $columns = array_flip($header);
        if (! isset($columns['name'], $columns['role'])) {
            fclose($handle);
            throw ValidationException::withMessages(['file' => ['الصف الأول يجب أن يحوي الأعمدة: name, phone, role, trade.']]);
        }
        $rows = [];
        $errors = [];
        $line = 1;
        while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $line++;
            if ($cells === [null] || implode('', array_map('trim', array_map('strval', $cells))) === '') {
                continue;
            }
            $value = fn (string $column, int $max) => isset($columns[$column]) && trim((string) ($cells[$columns[$column]] ?? '')) !== ''
                ? mb_substr(trim((string) $cells[$columns[$column]]), 0, $max) : null;
            $name = $value('name', 150);
            $roles = array_values(array_unique(array_filter(array_map(fn ($r) => strtolower(trim($r)), explode('|', (string) $value('role', 200))))));
            if ($name === null) {
                $errors[] = "السطر {$line}: الاسم مطلوب.";
            } elseif ($roles === [] || array_diff($roles, Party::ROLES) !== []) {
                $errors[] = "السطر {$line}: الدور يجب أن يكون من: ".implode('، ', Party::ROLES).'.';
            } else {
                $rows[] = ['name' => $name, 'phone' => $value('phone', 30), 'trade' => $value('trade', 100), 'roles' => $roles];
            }
            if (count($rows) + count($errors) > self::IMPORT_MAX_ROWS) {
                fclose($handle);
                throw ValidationException::withMessages(['file' => ['الحد الأقصى '.self::IMPORT_MAX_ROWS.' سطر في الملف.']]);
            }
        }
        fclose($handle);
        if ($errors !== []) {
            throw ValidationException::withMessages(['file' => array_slice($errors, 0, 50)]);
        }
        if ($rows === []) {
            throw ValidationException::withMessages(['file' => ['الملف لا يحوي أي جهة.']]);
        }

        return $rows;
    }
}
