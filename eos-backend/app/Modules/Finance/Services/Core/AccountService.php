<?php

namespace App\Modules\Finance\Services\Core;

use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\CashBox;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The chart of accounts, built by finance (spec §2.2). A child has its parent's type; a parent with postings cannot
 * get children; an account becomes a non-postable group when it gets its first child. Box accounts (is_system) and
 * accounts playing a system role are never deactivated.
 */
class AccountService
{
    private const TX_ATTEMPTS = 3;

    public function __construct(private SystemAccounts $roles, private AuditLogger $audit) {}

    /**
     * The whole chart as a tree, ordered by code.
     *
     * @return list<array<string, mixed>>
     */
    public function tree(bool $activeOnly = false): array
    {
        $accounts = Account::query()->when($activeOnly, fn ($q) => $q->where('is_active', true))->orderBy('code')->get();
        $boxes = CashBox::query()->pluck('id', 'account_id');
        $roles = [];
        foreach ($this->roles->all()['roles'] as $row) {
            if ($row['account_id'] !== null) {
                $roles[$row['account_id']][] = $row['role'];
            }
        }
        $nodes = [];
        foreach ($accounts as $account) {
            $nodes[$account->id] = [...$account->toArray(), 'roles' => $roles[$account->id] ?? [], 'cash_box_id' => $boxes[$account->id] ?? null, 'children' => []];
        }
        $tree = [];
        foreach (array_keys($nodes) as $id) {
            $parent = $nodes[$id]['parent_id'];
            if ($parent !== null && isset($nodes[$parent])) {
                $nodes[$parent]['children'][] = &$nodes[$id];
            } else {
                $tree[] = &$nodes[$id];
            }
        }

        return json_decode(json_encode($tree), true);
    }

    public function create(array $data, User $actor): Account
    {
        return DB::transaction(function () use ($data, $actor) {
            if (! empty($data['parent_id'])) {
                $parent = Account::query()->lockForUpdate()->findOrFail($data['parent_id']);
                $this->checkParent($parent, $data['type']);
                if ($parent->is_postable) {
                    $parent->update(['is_postable' => false, 'updated_by' => $actor->id]);
                }
            }
            $account = Account::create([...$data, 'code' => trim($data['code']), 'is_system' => false, 'created_by' => $actor->id, 'updated_by' => $actor->id]);
            $this->audit->log('finance.account_created', $account, null, $account->getAttributes(), $actor->id);

            return $account->refresh();
        }, self::TX_ATTEMPTS);
    }

    /** Type never changes. Box accounts can be renamed but keep their parent and stay postable and active. */
    public function update(Account $account, array $data, User $actor): Account
    {
        return DB::transaction(function () use ($account, $data, $actor) {
            $account = Account::query()->lockForUpdate()->findOrFail($account->id);
            unset($data['type'], $data['is_system']);
            if (array_key_exists('parent_id', $data) && (int) $data['parent_id'] !== (int) $account->parent_id) {
                abort_if($account->is_system, 422, 'حساب الصندوق يبقى تحت أب الصناديق.');
                if ($data['parent_id'] !== null) {
                    $parent = Account::query()->lockForUpdate()->findOrFail($data['parent_id']);
                    $this->checkParent($parent, $account->type);
                    for ($walk = $parent; $walk; $walk = $walk->parent_id ? Account::query()->find($walk->parent_id) : null) {
                        abort_if($walk->id === $account->id, 422, 'لا يمكن نقل الحساب تحت أحد فروعه.');
                    }
                    if ($parent->is_postable) {
                        $parent->update(['is_postable' => false, 'updated_by' => $actor->id]);
                    }
                }
            }
            if (($data['is_postable'] ?? false) && Account::query()->where('parent_id', $account->id)->exists()) {
                throw ValidationException::withMessages(['is_postable' => ['للحساب حسابات فرعية؛ يبقى تجميعياً.']]);
            }
            if (array_key_exists('is_postable', $data) && ! $data['is_postable'] && ($account->is_system || $this->roles->isRoleAccount($account->id, ['cash_boxes_parent']))) {
                throw ValidationException::withMessages(['is_postable' => ['حساب الصندوق أو حساب الدور يبقى قابلاً للترحيل.']]);
            }
            if (array_key_exists('is_active', $data) && ! $data['is_active'] && $account->is_active) {
                $this->assertCanDeactivate($account);
            }
            $old = $account->getAttributes();
            $account->update([...$data, 'updated_by' => $actor->id]);
            $this->audit->log('finance.account_updated', $account, $old, $account->getAttributes(), $actor->id);

            return $account->refresh();
        }, self::TX_ATTEMPTS);
    }

    public function deactivate(Account $account, User $actor): Account
    {
        return $this->update($account, ['is_active' => false], $actor);
    }

    private function assertCanDeactivate(Account $account): void
    {
        abort_if($account->is_system, 422, 'لا يُعطَّل حساب صندوق.');
        $roles = $this->roles->rolesOf($account->id);
        if ($roles !== []) {
            throw ValidationException::withMessages(['is_active' => ['الحساب مربوط بالدور "'.SystemAccounts::label($roles[0]).'"؛ غيّر الربط أولاً.']]);
        }
        abort_if(Account::query()->where('parent_id', $account->id)->where('is_active', true)->exists(), 422, 'عطّل الحسابات الفرعية أولاً.');
    }

    /** Same type; no postings on the parent; never under a box account or a postable role account. */
    private function checkParent(Account $parent, string $type): void
    {
        abort_unless($parent->type === $type, 422, 'الحساب الفرعي يجب أن يكون من نوع الحساب الأب.');
        abort_if($parent->is_system, 422, 'لا تُضاف حسابات فرعية تحت حساب صندوق.');
        abort_if(JournalLine::query()->where('account_id', $parent->id)->exists(), 422, 'للحساب الأب حركات؛ لا يمكن أن يصبح حساباً تجميعياً.');
        if ($this->roles->isRoleAccount($parent->id, ['cash_boxes_parent'])) {
            throw ValidationException::withMessages(['parent_id' => ['الحساب الأب مربوط بدور نظام يقبل القيود؛ اختر أباً آخر أو غيّر الربط.']]);
        }
    }
}
